<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra hóa đơn mua hàng bị hạch toán sai loại.
 *
 * Lỗi điển hình:
 *   L1 – Hóa đơn có stock_entry confirmed nhưng JE lại Dr chi phí / Cr 331 (service path).
 *   L2 – Hóa đơn hàng hóa có JE posted nhưng không có stock_entry (thiếu nhập kho).
 *   L3 – Hóa đơn (goods) có stock_entry confirmed nhưng JE 1561 không khớp subtotal.
 *   L4 – JE Cr 3311 không khớp invoice.total (sai công nợ NCC).
 *
 * Chỉ audit — không sửa dữ liệu. Dùng php artisan purchase-invoices:fix-accounting --apply để sửa.
 */
class PurchaseInvoicesAuditAccounting extends Command
{
    protected $signature = 'purchase-invoices:audit-accounting
        {--limit=50  : Số dòng tối đa mỗi check}
        {--export    : Ghi kết quả ra storage/logs/pi_audit_*.csv}';

    protected $description = 'Kiểm tra hóa đơn mua hàng bị hạch toán sai loại';

    private int $totalIssues = 0;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info('=== PURCHASE INVOICE ACCOUNTING AUDIT ===');
        $this->line('');

        $this->checkL1($limit);
        $this->checkL2($limit);
        $this->checkL3($limit);
        $this->checkL4($limit);

        $this->line('');
        if ($this->totalIssues === 0) {
            $this->info('✓ Không phát hiện vấn đề hạch toán hóa đơn mua hàng.');
        } else {
            $this->error("Tổng cộng: {$this->totalIssues} vấn đề.");
            $this->line('Xem chi tiết ở trên. Chạy php artisan purchase-invoices:fix-accounting --apply để sửa dữ liệu an toàn.');
        }

        return $this->totalIssues === 0 ? self::SUCCESS : self::FAILURE;
    }

    // L1: HĐ có confirmed stock_entry nhưng JE là Dr chi phí / Cr 331 (sai: phải Dr 1561+1331 / Cr 331)
    private function checkL1(int $limit): void
    {
        // Tìm purchase invoices có:
        //  a) JE source_type = purchase_invoice (service path đã post)
        //  b) PO của HĐ có ít nhất 1 confirmed stock_entry
        $rows = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->whereExists(function ($sub) {
                // Đã có JE từ service path (PurchaseInvoiceService::postInvoiceEntryIfNeeded)
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->where('je.reference_type', 'purchase_invoice')
                    ->whereColumn('je.reference_id', 'pi.id')
                    ->whereIn('je.status', ['draft', 'posted']);
            })
            ->whereExists(function ($sub) {
                // PO có stock_entry đã confirmed (hàng đã nhập kho → StockService đã post JE hàng hóa)
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se')
                    ->whereColumn('se.purchase_order_id', 'po.id')
                    ->where('se.status', 'confirmed');
            })
            ->whereNotIn('pi.status', ['cancelled'])
            ->select([
                'pi.id',
                'pi.code',
                'pi.invoice_date',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.total',
                'pi.status',
                DB::raw('s.name as supplier_name'),
                DB::raw('po.code as po_code'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->totalIssues += $rows->count();
            $this->warn("  L1 ✗ HĐ có confirmed stock_entry nhưng vẫn tồn tại JE từ service path: {$rows->count()} HĐ");
            $this->line('  Lý do thường gặp: HĐ được duyệt trước khi NK xác nhận, hoặc isGoodsPurchase() trả false sai.');
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày', 'Trước thuế', 'Thuế', 'Tổng', 'Trạng thái', 'NCC', 'ĐM'],
                $rows->map(fn ($r) => [
                    $r->id, $r->code, $r->invoice_date,
                    number_format($r->subtotal),
                    number_format($r->tax_amount),
                    number_format($r->total),
                    $r->status,
                    mb_strimwidth($r->supplier_name, 0, 25, '…'),
                    $r->po_code,
                ])->all()
            );
            $this->line('  → php artisan purchase-invoices:fix-accounting --apply sẽ đảo JE service và tạo lại đúng loại.');
            $this->line('');
        } else {
            $this->line('  L1 ✓ Không có HĐ hàng hóa bị hạch toán nhầm service path.');
        }
    }

    // L2: HĐ hàng hóa (có goods PO items) nhưng KHÔNG có stock_entry => hàng chưa nhập kho
    //     Đây không hẳn là lỗi JE, nhưng cần cảnh báo kế toán
    private function checkL2(int $limit): void
    {
        $rows = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            // HĐ có goods PO items
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('purchase_order_items as poi')
                    ->whereColumn('poi.purchase_order_id', 'po.id')
                    ->whereNotNull('poi.product_id')
                    ->whereNotIn('poi.line_type', ['service', 'fixed_asset']);
            })
            // Nhưng KHÔNG có confirmed stock_entry
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se')
                    ->whereColumn('se.purchase_order_id', 'po.id')
                    ->where('se.status', 'confirmed');
            })
            ->whereIn('pi.status', ['valid', 'partial_paid', 'paid'])
            ->select([
                'pi.id',
                'pi.code',
                'pi.invoice_date',
                'pi.total',
                'pi.status',
                DB::raw('s.name as supplier_name'),
                DB::raw('po.code as po_code'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->totalIssues += $rows->count();
            $this->warn("  L2 ✗ HĐ hàng hóa đã hợp lệ/thanh toán nhưng chưa có phiếu nhập kho confirmed: {$rows->count()} HĐ");
            $this->line('  Hàng chưa nhập kho → chưa có JE Nợ 1561/1331 / Có 3311. Công nợ NCC chưa phát sinh.');
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày', 'Tổng', 'Trạng thái', 'NCC', 'ĐM'],
                $rows->map(fn ($r) => [
                    $r->id, $r->code, $r->invoice_date,
                    number_format($r->total),
                    $r->status,
                    mb_strimwidth($r->supplier_name, 0, 25, '…'),
                    $r->po_code,
                ])->all()
            );
            $this->line('  → Tạo phiếu nhập kho và confirm để phát sinh JE tự động.');
            $this->line('');
        } else {
            $this->line('  L2 ✓ Không có HĐ hàng hóa hợp lệ mà thiếu phiếu nhập kho.');
        }
    }

    // L3: Stock entry confirmed nhưng JE 1561 (Nợ) không khớp subtotal hóa đơn liên quan
    private function checkL3(int $limit): void
    {
        $rows = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            // Có confirmed stock_entry
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se')
                    ->whereColumn('se.purchase_order_id', 'po.id')
                    ->where('se.status', 'confirmed');
            })
            ->whereNotIn('pi.status', ['cancelled', 'pending'])
            // Có JE stock_entry nhưng tổng Nợ 1561 khác subtotal HĐ (dung sai 1000)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se2')
                    ->whereColumn('se2.purchase_order_id', 'po.id')
                    ->where('se2.status', 'confirmed')
                    ->whereExists(function ($sub2) {
                        $sub2->select(DB::raw(1))
                            ->from('journal_entries as je')
                            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                            ->whereColumn('je.source_id', 'se2.id')
                            ->where('je.source_type', 'stock_entry')
                            ->where('je.status', 'posted')
                            ->where('jel.account_code', 'like', '156%')
                            ->where('jel.debit', '>', 0);
                    });
            })
            ->select([
                'pi.id',
                'pi.code',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.total',
                DB::raw('po.code as po_code'),
                DB::raw('s.name as supplier_name'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        // Refine: chỉ giữ những HĐ có JE 1561 chênh lệch > 1000 so với subtotal
        $issues = $rows->filter(function ($r) {
            $je1561 = DB::table('journal_entries as je')
                ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                ->join('stock_entries as se', function ($join) use ($r) {
                    $join->on('se.id', '=', 'je.source_id')
                         ->whereExists(function ($sub) use ($r) {
                             $sub->select(DB::raw(1))
                                 ->from('purchase_orders as po')
                                 ->where('po.id', DB::raw('se.purchase_order_id'))
                                 ->join('purchase_invoices as pi', 'pi.purchase_order_id', '=', 'po.id')
                                 ->where('pi.id', $r->id);
                         });
                })
                ->where('je.source_type', 'stock_entry')
                ->where('je.status', 'posted')
                ->where('jel.account_code', 'like', '156%')
                ->where('jel.debit', '>', 0)
                ->sum('jel.debit');

            return abs((float)$je1561 - (float)$r->subtotal) > 1000;
        });

        if ($issues->isNotEmpty()) {
            $this->totalIssues += $issues->count();
            $this->warn("  L3 ✗ JE 1561 từ NK không khớp subtotal hóa đơn: {$issues->count()} HĐ");
            $this->table(
                ['ID', 'Mã HĐ', 'Subtotal HĐ', 'Thuế', 'Tổng', 'ĐM', 'NCC'],
                $issues->map(fn ($r) => [
                    $r->id, $r->code,
                    number_format($r->subtotal),
                    number_format($r->tax_amount),
                    number_format($r->total),
                    $r->po_code,
                    mb_strimwidth($r->supplier_name, 0, 20, '…'),
                ])->all()
            );
            $this->line('  → Có thể NK nhập giá khác HĐ, hoặc NK chưa khớp đầy đủ với HĐ. Cần kế toán xem xét.');
            $this->line('');
        } else {
            $this->line('  L3 ✓ JE 1561 khớp subtotal hóa đơn mua hàng.');
        }
    }

    // L4: JE Cr 3311 (NCC payable) từ purchase_invoice_payment không khớp payment.amount
    private function checkL4(int $limit): void
    {
        $rows = DB::table('purchase_invoice_payments as pip')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pip.purchase_invoice_id')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->where('pip.status', 'active')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                    ->where('je.source_type', 'purchase_invoice_payment')
                    ->whereColumn('je.source_id', 'pip.id')
                    ->where('je.status', 'posted')
                    ->where(fn ($q) => $q->where('jel.account_code', 'like', '331%')->where('jel.debit', '>', 0));
            })
            ->select([
                'pi.code as pi_code',
                'pip.id as pip_id',
                'pip.amount as pip_amount',
                'pip.payment_date',
                DB::raw('s.name as supplier_name'),
            ])
            ->orderByDesc('pip.id')
            ->limit($limit)
            ->get();

        $issues = $rows->filter(function ($r) {
            $je331 = DB::table('journal_entries as je')
                ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
                ->where('je.source_type', 'purchase_invoice_payment')
                ->where('je.source_id', $r->pip_id)
                ->where('je.status', 'posted')
                ->where('jel.account_code', 'like', '331%')
                ->where('jel.debit', '>', 0)
                ->sum('jel.debit');

            return abs((float)$je331 - (float)$r->pip_amount) > 1;
        });

        if ($issues->isNotEmpty()) {
            $this->totalIssues += $issues->count();
            $this->warn("  L4 ✗ JE Nợ 331 (trả NCC) không khớp số tiền thanh toán: {$issues->count()} bản ghi");
            $this->table(
                ['HĐ', 'Payment ID', 'Số tiền TT', 'Ngày TT', 'NCC'],
                $issues->map(fn ($r) => [
                    $r->pi_code, $r->pip_id,
                    number_format($r->pip_amount),
                    $r->payment_date,
                    mb_strimwidth($r->supplier_name, 0, 25, '…'),
                ])->all()
            );
            $this->line('  → Cần kế toán tạo bút toán điều chỉnh thủ công.');
            $this->line('');
        } else {
            $this->line('  L4 ✓ JE Nợ 331 (trả NCC) khớp số tiền thanh toán.');
        }
    }
}
