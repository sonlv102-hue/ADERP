<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra toàn bộ tính nhất quán của VAT trong hệ thống.
 *
 * Scope purchase (luồng mua):
 *   P1 – PO items có vat_rate NULL/0 nhưng product.vat_percent > 0
 *   P2 – stock_entry_items có tax_rate khác vat_rate PO item gốc
 *   P3 – purchase invoices: header tax_amount không khớp tổng tính từ PO items
 *   P4 – JE Dr 1331/1332 không khớp tax từ stock entry
 *
 * Scope sales (luồng bán):
 *   S1 – order_items có vat_rate = 0 nhưng invoice.tax_amount > 0
 *   S2 – invoices: JE Cr 3331 không khớp invoice.tax_amount
 *   S3 – doanh thu báo cáo đang bao gồm VAT (Cr 5111 ≥ invoice total thay vì subtotal)
 *
 * Không sửa dữ liệu. Chỉ báo cáo.
 */
class VatAudit extends Command
{
    protected $signature = 'vat:audit
        {--scope=all    : purchase | sales | all}
        {--limit=100    : Số dòng tối đa mỗi check}
        {--export       : Ghi kết quả ra storage/logs/vat_audit_*.csv}';

    protected $description = 'Kiểm tra tính nhất quán VAT toàn hệ thống (purchase / sales / accounting)';

    private int $totalIssues = 0;

    public function handle(): int
    {
        $scope  = $this->option('scope');
        $limit  = (int) $this->option('limit');
        $export = $this->option('export');

        $this->info("=== VAT AUDIT — scope: {$scope} ===");
        $this->line('');

        if (in_array($scope, ['all', 'purchase'])) {
            $this->auditPurchase($limit);
        }

        if (in_array($scope, ['all', 'sales'])) {
            $this->auditSales($limit);
        }

        if (in_array($scope, ['all', 'accounting'])) {
            $this->auditAccounting($limit);
        }

        $this->line('');
        if ($this->totalIssues === 0) {
            $this->info('✓ Không phát hiện vấn đề VAT.');
        } else {
            $this->error("Tổng cộng: {$this->totalIssues} vấn đề cần xem xét.");
            $this->line('Chạy php artisan vat:fix --scope=purchase|sales --apply để sửa tự động (chỉ dữ liệu an toàn).');
        }

        return $this->totalIssues === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ─── PURCHASE AUDIT ──────────────────────────────────────────────────────

    private function auditPurchase(int $limit): void
    {
        $this->info('── LUỒNG MUA HÀNG ──────────────────────────────────────');

        // P1: PO items có vat_rate NULL/0 nhưng product.vat_percent > 0
        $p1 = DB::table('purchase_order_items as poi')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('p.vat_percent', '>', 0)
            ->where(fn ($q) => $q->whereNull('poi.vat_rate')->orWhere('poi.vat_rate', 0))
            ->select([
                'po.code as po_code',
                'po.order_date',
                DB::raw('p.code as product_code'),
                DB::raw('p.name as product_name'),
                DB::raw('p.vat_percent'),
                'poi.vat_rate',
                'poi.quantity',
                'poi.unit_price',
            ])
            ->orderByDesc('po.id')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'P1',
            'PO items có vat_rate NULL/0 nhưng sản phẩm có vat_percent > 0',
            $p1,
            ['Đơn mua', 'Ngày', 'Mã SP', 'Tên SP', 'vat_percent SP', 'vat_rate PO item', 'SL', 'Đơn giá'],
            fn ($r) => [
                $r->po_code, $r->order_date,
                $r->product_code,
                mb_strimwidth($r->product_name, 0, 30, '…'),
                $r->vat_percent . '%',
                is_null($r->vat_rate) ? 'NULL' : $r->vat_rate . '%',
                $r->quantity,
                number_format($r->unit_price),
            ],
            'Chạy php artisan vat:fix --scope=purchase --apply để backfill vat_rate từ product.vat_percent.'
        );

        // P2: stock_entry_items có tax_rate khác vat_rate của PO item gốc
        $p2 = DB::table('stock_entry_items as sei')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'sei.purchase_order_item_id')
            ->join('stock_entries as se', 'se.id', '=', 'sei.stock_entry_id')
            ->whereNotNull('sei.purchase_order_item_id')
            ->whereRaw('ABS(COALESCE(sei.tax_rate, 0) - COALESCE(poi.vat_rate, 0)) > 0.01')
            ->select([
                'se.code as se_code',
                'se.entry_date',
                DB::raw('sei.tax_rate as sei_tax_rate'),
                DB::raw('poi.vat_rate as poi_vat_rate'),
                'sei.unit_price',
                'sei.quantity',
            ])
            ->orderByDesc('se.id')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'P2',
            'stock_entry_items có tax_rate khác vat_rate PO item gốc',
            $p2,
            ['Phiếu NK', 'Ngày NK', 'tax_rate NK', 'vat_rate PO', 'Đơn giá', 'SL'],
            fn ($r) => [
                $r->se_code, $r->entry_date,
                $r->sei_tax_rate . '%',
                ($r->poi_vat_rate ?? 'NULL') . '%',
                number_format($r->unit_price),
                $r->quantity,
            ],
            'Kiểm tra thủ công hoặc chạy php artisan vat:fix --scope=purchase --apply.'
        );

        // P3: Purchase invoices có header tax_amount > 0 nhưng tất cả PO items vat_rate = 0/NULL
        $p3 = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->where('pi.tax_amount', '>', 0)
            ->whereNotIn('pi.status', ['cancelled'])
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('purchase_order_items as poi')
                    ->whereColumn('poi.purchase_order_id', 'po.id')
                    ->where('poi.vat_rate', '>', 0);
            })
            ->select([
                'pi.code',
                'pi.invoice_date',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.total',
                DB::raw('po.code as po_code'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'P3',
            'Purchase invoices: tax_amount > 0 nhưng tất cả PO items có vat_rate = 0/NULL',
            $p3,
            ['Hóa đơn', 'Ngày', 'Trước thuế', 'Thuế', 'Tổng', 'Đơn mua'],
            fn ($r) => [
                $r->code, $r->invoice_date,
                number_format($r->subtotal),
                number_format($r->tax_amount),
                number_format($r->total),
                $r->po_code,
            ],
            'Cần kế toán xem xét: vat_rate dòng hàng không khớp với tax_amount header hóa đơn.'
        );

        // P4: JE 1331 cho stock_entry có số tiền ≠ tổng tính từ items
        $p4 = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', function ($join) {
                $join->on('jel.journal_entry_id', '=', 'je.id')
                     ->where('jel.account_code', 'like', '133%')
                     ->where('jel.debit', '>', 0);
            })
            ->where('je.reference_type', 'stock_entry')
            ->whereNotNull('je.reference_id')
            ->whereIn('je.status', ['posted'])
            // Tính tổng JE 1331 theo reference_id
            ->selectRaw('
                je.reference_id as stock_entry_id,
                SUM(jel.debit) as je_vat
            ')
            ->groupBy('je.reference_id')
            ->havingRaw('ABS(SUM(jel.debit) - COALESCE((
                SELECT SUM(sei.unit_price * sei.quantity * sei.tax_rate / 100)
                FROM stock_entry_items sei
                WHERE sei.stock_entry_id = je.reference_id
            ), 0)) > 1')
            ->limit($limit)
            ->get();

        if ($p4->isNotEmpty()) {
            // Enrich with stock entry codes
            $seIds = $p4->pluck('stock_entry_id');
            $seCodes = DB::table('stock_entries')->whereIn('id', $seIds)->pluck('code', 'id');
            $enriched = $p4->map(fn ($r) => (object) [
                'code'             => $seCodes[$r->stock_entry_id] ?? "SE#{$r->stock_entry_id}",
                'stock_entry_id'   => $r->stock_entry_id,
                'je_vat'           => $r->je_vat,
            ]);
            $this->reportCheck(
                'P4',
                'JE Dr 1331 từ phiếu nhập kho không khớp tổng VAT tính từ items',
                $enriched,
                ['Phiếu NK', 'ID', 'JE 1331'],
                fn ($r) => [$r->code, $r->stock_entry_id, number_format($r->je_vat)],
                'Cần kế toán đối chiếu thủ công và tạo bút toán điều chỉnh nếu cần.'
            );
        } else {
            $this->line('  P4 ✓ JE Dr 1331 khớp tổng VAT phiếu nhập kho.');
        }
    }

    // ─── SALES AUDIT ─────────────────────────────────────────────────────────

    private function auditSales(int $limit): void
    {
        $this->line('');
        $this->info('── LUỒNG BÁN HÀNG ──────────────────────────────────────');

        // S1: invoices có tax_amount > 0 nhưng tất cả order_items vat_rate = 0
        $s1 = DB::table('invoices as inv')
            ->join('orders as ord', 'ord.id', '=', 'inv.order_id')
            ->where('inv.tax_amount', '>', 0)
            ->whereNotIn('inv.status', ['cancelled'])
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('order_items as oi')
                    ->whereColumn('oi.order_id', 'ord.id')
                    ->where('oi.vat_rate', '>', 0);
            })
            ->select([
                'inv.code',
                'inv.issue_date',
                'inv.subtotal',
                'inv.tax_amount',
                'inv.total',
                DB::raw('ord.code as order_code'),
            ])
            ->orderByDesc('inv.id')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'S1',
            'Hóa đơn bán: tax_amount > 0 nhưng tất cả order_items có vat_rate = 0',
            $s1,
            ['Hóa đơn', 'Ngày', 'Trước thuế', 'Thuế', 'Tổng', 'Đơn hàng'],
            fn ($r) => [
                $r->code, $r->invoice_date,
                number_format($r->subtotal),
                number_format($r->tax_amount),
                number_format($r->total),
                $r->order_code,
            ],
            'VAT dòng hàng không khớp header. Cần kiểm tra vat_rate trên order_items.'
        );

        // S2: JE Cr 3331 ≠ invoice.tax_amount
        $s2 = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', function ($join) {
                $join->on('jel.journal_entry_id', '=', 'je.id')
                     ->where('jel.account_code', 'like', '333%')
                     ->where('jel.credit', '>', 0);
            })
            ->join('invoices as inv', 'inv.id', '=', 'je.reference_id')
            ->where('je.reference_type', 'invoice')
            ->whereIn('je.status', ['posted'])
            ->selectRaw('
                inv.code as invoice_code,
                inv.issue_date,
                inv.tax_amount as invoice_vat,
                SUM(jel.credit) as je_vat
            ')
            ->groupBy('inv.code', 'inv.issue_date', 'inv.tax_amount')
            ->havingRaw('ABS(SUM(jel.credit) - inv.tax_amount) > 1')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'S2',
            'JE Cr 3331 không khớp tax_amount hóa đơn bán',
            $s2,
            ['Hóa đơn', 'Ngày', 'Tax HĐ', 'JE 3331', 'Chênh lệch'],
            fn ($r) => [
                $r->invoice_code, $r->invoice_date,
                number_format($r->invoice_vat),
                number_format($r->je_vat),
                number_format(abs($r->je_vat - $r->invoice_vat)),
            ],
            'Cần kế toán tạo bút toán điều chỉnh 3331.'
        );

        // S3: Doanh thu báo cáo (JE Cr 5111/5113) lớn hơn đáng kể so với invoice.subtotal
        // (dấu hiệu bao gồm VAT vào doanh thu)
        $s3 = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', function ($join) {
                $join->on('jel.journal_entry_id', '=', 'je.id')
                     ->where(fn ($q) => $q->where('jel.account_code', 'like', '511%'))
                     ->where('jel.credit', '>', 0);
            })
            ->join('invoices as inv', 'inv.id', '=', 'je.reference_id')
            ->where('je.reference_type', 'invoice')
            ->whereIn('je.status', ['posted'])
            ->where('inv.tax_amount', '>', 0)
            ->selectRaw('
                inv.code as invoice_code,
                inv.issue_date,
                inv.subtotal,
                inv.tax_amount,
                inv.total,
                SUM(jel.credit) as je_revenue
            ')
            ->groupBy('inv.code', 'inv.issue_date', 'inv.subtotal', 'inv.tax_amount', 'inv.total')
            // Cờ đỏ: JE doanh thu > subtotal + 1% dung sai (nghĩa là bao gồm cả VAT)
            ->havingRaw('SUM(jel.credit) > inv.subtotal * 1.01')
            ->limit($limit)
            ->get();

        $this->reportCheck(
            'S3',
            'Doanh thu JE (5111/5113) > subtotal hóa đơn — có thể đang bao gồm VAT',
            $s3,
            ['Hóa đơn', 'Ngày', 'Subtotal HĐ', 'JE 511x', 'Thuế HĐ', 'Chênh lệch'],
            fn ($r) => [
                $r->invoice_code, $r->invoice_date,
                number_format($r->subtotal),
                number_format($r->je_revenue),
                number_format($r->tax_amount),
                number_format($r->je_revenue - $r->subtotal),
            ],
            'Cần kiểm tra InvoiceService: doanh thu phải = subtotal (chưa VAT), không phải total.'
        );
    }

    // ─── ACCOUNTING RECONCILE ─────────────────────────────────────────────────

    private function auditAccounting(int $limit): void
    {
        $this->line('');
        $this->info('── ĐỐI CHIẾU KẾ TOÁN ────────────────────────────────────');

        // A1: Tổng Dr 1331 (posted) vs tổng tax_amount purchase invoices (valid/paid)
        $je1331 = (float) DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_code', '1331')
            ->where('je.status', 'posted')
            ->sum('jel.debit');

        $piTax = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled', 'pending'])
            ->sum('tax_amount');

        $diff1331 = abs($je1331 - $piTax);
        if ($diff1331 > 1000) {
            $this->warn("  A1 ✗ Tổng Dr 1331 ≠ tổng tax_amount hóa đơn mua:");
            $this->warn("       JE 1331: " . number_format($je1331) . " đ");
            $this->warn("       PI tax : " . number_format($piTax) . " đ");
            $this->warn("       Chênh  : " . number_format($diff1331) . " đ");
            $this->warn("  Lưu ý: 1331 còn bao gồm dịch vụ, TSCĐ. Chênh lệch lớn cần điều tra.");
            $this->totalIssues++;
        } else {
            $this->line("  A1 ✓ Tổng Dr 1331 xấp xỉ tổng tax_amount hóa đơn mua (~" . number_format($diff1331) . " đ chênh lệch).");
        }

        // A2: Tổng Cr 3331 (posted) vs tổng tax_amount invoices (bán)
        $je3331 = (float) DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_code', 'like', '3331%')
            ->where('je.status', 'posted')
            ->sum('jel.credit');

        $invTax = (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->sum('tax_amount');

        $diff3331 = abs($je3331 - $invTax);
        if ($diff3331 > 1000) {
            $this->warn("  A2 ✗ Tổng Cr 3331 ≠ tổng tax_amount hóa đơn bán:");
            $this->warn("       JE 3331 : " . number_format($je3331) . " đ");
            $this->warn("       Inv tax : " . number_format($invTax) . " đ");
            $this->warn("       Chênh   : " . number_format($diff3331) . " đ");
            $this->totalIssues++;
        } else {
            $this->line("  A2 ✓ Tổng Cr 3331 xấp xỉ tổng tax_amount hóa đơn bán (~" . number_format($diff3331) . " đ chênh lệch).");
        }

        // A3: Tồn kho 1561 (từ JE) vs tồn kho thực từ stock_movements
        $je1561Debit  = (float) DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_code', 'like', '156%')
            ->where('je.status', 'posted')
            ->sum('jel.debit');

        $je1561Credit = (float) DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_code', 'like', '156%')
            ->where('je.status', 'posted')
            ->sum('jel.credit');

        $je1561Net = $je1561Debit - $je1561Credit;

        $this->line("  A3 ℹ Số dư 1561/1562 từ JE: " . number_format($je1561Net) . " đ");
        $this->line("     (đối chiếu với kho thực bằng lệnh inventory:audit)");
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function reportCheck(
        string $code,
        string $title,
        $rows,
        array $headers,
        callable $rowMapper,
        string $hint
    ): void {
        if ($rows->isEmpty()) {
            $this->line("  {$code} ✓ {$title}: OK");
            return;
        }

        $this->totalIssues += $rows->count();
        $this->warn("  {$code} ✗ {$title}: {$rows->count()} vấn đề");
        $this->table($headers, $rows->map($rowMapper)->all());
        $this->line("  → {$hint}");
        $this->line('');
    }
}
