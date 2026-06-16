<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tự động sửa các vấn đề VAT an toàn (suy ra chắc chắn được).
 *
 * Scope purchase:
 *   FP1 – Backfill vat_rate trên PO items từ product.vat_percent (khi null)
 *   FP2 – Backfill tax_rate trên stock_entry_items từ PO item gốc (khi liên kết qua purchase_order_item_id)
 *   FP3 – Rebuild subtotal/tax_amount trên purchase_invoices khi header không khớp
 *          (chỉ khi: 1 mức VAT duy nhất, có thể suy ra chắc chắn)
 *
 * Scope sales:
 *   FS1 – Backfill vat_rate trên order_items từ product.vat_percent (khi null/0 và product có vat_percent > 0)
 *
 * Luôn hiển thị dry-run trước. Thêm --apply để lưu.
 * Không tự sửa nếu không suy ra chắc chắn — đưa vào danh sách manual review.
 */
class VatFix extends Command
{
    protected $signature = 'vat:fix
        {--scope=purchase  : purchase | sales}
        {--apply           : Thực sự lưu dữ liệu (không có flag này = dry-run)}
        {--limit=500       : Số dòng tối đa mỗi fix}';

    protected $description = 'Sửa tự động các vấn đề VAT an toàn (dùng --apply để lưu)';

    public function handle(): int
    {
        $scope  = $this->option('scope');
        $apply  = (bool) $this->option('apply');
        $limit  = (int) $this->option('limit');

        $tag = $apply ? '[APPLY]' : '[DRY-RUN]';
        $this->info("=== VAT FIX — scope: {$scope} {$tag} ===");
        $this->line('');

        if (!$apply) {
            $this->warn('Chế độ DRY-RUN: chỉ hiển thị sẽ sửa gì. Thêm --apply để lưu.');
            $this->line('');
        }

        $totalFixed = 0;

        if (in_array($scope, ['purchase'])) {
            $totalFixed += $this->fixPurchase($apply, $limit);
        }

        if (in_array($scope, ['sales'])) {
            $totalFixed += $this->fixSales($apply, $limit);
        }

        $this->line('');
        $this->info($apply
            ? "Đã sửa: {$totalFixed} bản ghi."
            : "Sẽ sửa: {$totalFixed} bản ghi. Chạy lại với --apply để lưu.");

        return self::SUCCESS;
    }

    // ─── PURCHASE FIXES ──────────────────────────────────────────────────────

    private function fixPurchase(bool $apply, int $limit): int
    {
        $this->info('── FP1: Backfill vat_rate trên PO items từ product.vat_percent ──');

        $rows = DB::table('purchase_order_items as poi')
            ->join('products as p', 'p.id', '=', 'poi.product_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereNull('poi.vat_rate')
            ->select([
                'poi.id as poi_id',
                'po.code as po_code',
                DB::raw('p.code as product_code'),
                DB::raw('p.vat_percent as new_vat_rate'),
            ])
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  FP1 ✓ Không có PO item nào có vat_rate NULL.');
        } else {
            $this->line("  Sẽ cập nhật {$rows->count()} PO items:");
            $this->table(
                ['PO item ID', 'Đơn mua', 'Sản phẩm', 'vat_rate mới'],
                $rows->take(20)->map(fn ($r) => [
                    $r->poi_id, $r->po_code, $r->product_code, $r->new_vat_rate . '%',
                ])->all()
            );
            if ($rows->count() > 20) $this->line('  ... và ' . ($rows->count() - 20) . ' dòng khác.');

            if ($apply) {
                DB::statement("
                    UPDATE purchase_order_items
                    SET vat_rate = COALESCE(
                        (SELECT vat_percent FROM products WHERE products.id = purchase_order_items.product_id),
                        0
                    )
                    WHERE vat_rate IS NULL
                ");
                $this->info("  ✓ Đã cập nhật {$rows->count()} PO items.");
            }
        }

        $fp1Count = $rows->count();

        // FP2: Backfill tax_rate trên stock_entry_items từ PO item khi tax_rate mặc định = 10
        // nhưng PO item vat_rate khác 10 (và liên kết qua purchase_order_item_id)
        $this->line('');
        $this->info('── FP2: Backfill tax_rate trên stock_entry_items từ PO item gốc ──');

        $fp2Rows = DB::table('stock_entry_items as sei')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'sei.purchase_order_item_id')
            ->join('stock_entries as se', 'se.id', '=', 'sei.stock_entry_id')
            ->whereNotNull('sei.purchase_order_item_id')
            ->whereRaw('ABS(COALESCE(sei.tax_rate, 10) - COALESCE(poi.vat_rate, 10)) > 0.01')
            ->whereNotNull('poi.vat_rate')
            ->select([
                'sei.id as sei_id',
                'se.code as se_code',
                DB::raw('sei.tax_rate as old_rate'),
                DB::raw('poi.vat_rate as new_rate'),
            ])
            ->limit($limit)
            ->get();

        if ($fp2Rows->isEmpty()) {
            $this->line('  FP2 ✓ tax_rate trên stock_entry_items đã khớp với PO item gốc.');
        } else {
            $this->line("  Sẽ cập nhật {$fp2Rows->count()} stock_entry_items:");
            $this->table(
                ['SEI ID', 'Phiếu NK', 'tax_rate cũ', 'tax_rate mới'],
                $fp2Rows->take(20)->map(fn ($r) => [
                    $r->sei_id, $r->se_code, $r->old_rate . '%', $r->new_rate . '%',
                ])->all()
            );
            if ($fp2Rows->count() > 20) $this->line('  ... và ' . ($fp2Rows->count() - 20) . ' dòng khác.');
            $this->warn('  Lưu ý FP2: Chỉ cập nhật tax_rate cho tham chiếu tương lai. Bút toán đã posted không thay đổi.');
            $this->warn('  Nếu bút toán sai, cần tạo bút toán điều chỉnh thủ công.');

            if ($apply) {
                foreach ($fp2Rows as $row) {
                    DB::table('stock_entry_items')
                        ->where('id', $row->sei_id)
                        ->update(['tax_rate' => $row->new_rate]);
                }
                $this->info("  ✓ Đã cập nhật {$fp2Rows->count()} stock_entry_items.");
            }
        }

        // FP3: Tìm purchase invoices có thể rebuild subtotal/tax từ PO items
        // (an toàn khi: tất cả items có cùng vat_rate — 1 mức thuế duy nhất)
        $this->line('');
        $this->info('── FP3: Kiểm tra rebuild subtotal/tax_amount hóa đơn mua ──');

        $fp3Candidates = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->where('pi.tax_amount', '>', 0)
            ->whereNotIn('pi.status', ['cancelled'])
            // Tất cả PO items có cùng vat_rate (an toàn để rebuild)
            ->whereRaw('(
                SELECT COUNT(DISTINCT COALESCE(poi2.vat_rate, 0))
                FROM purchase_order_items poi2
                WHERE poi2.purchase_order_id = po.id
            ) = 1')
            // vat_rate tất cả items > 0
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('purchase_order_items as poi3')
                    ->whereColumn('poi3.purchase_order_id', 'po.id')
                    ->where('poi3.vat_rate', '>', 0);
            })
            ->select([
                'pi.id',
                'pi.code',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.total',
            ])
            ->orderByDesc('pi.id')
            ->limit(50)
            ->get();

        $fp3Issues = [];
        foreach ($fp3Candidates as $pi) {
            // Tính lại từ PO items
            $poItems = DB::table('purchase_order_items as poi')
                ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->join('purchase_invoices as pinv', 'pinv.purchase_order_id', '=', 'po.id')
                ->where('pinv.id', $pi->id)
                ->select(['poi.quantity', 'poi.unit_price', 'poi.vat_rate'])
                ->get();

            $calcSubtotal = 0;
            $calcTax      = 0;
            foreach ($poItems as $item) {
                $lineExcl = (float)$item->unit_price * (float)$item->quantity;
                $lineTax  = $lineExcl * ((float)($item->vat_rate ?? 0)) / 100;
                $calcSubtotal += $lineExcl;
                $calcTax      += $lineTax;
            }

            if (abs($calcSubtotal - (float)$pi->subtotal) > 100 || abs($calcTax - (float)$pi->tax_amount) > 100) {
                $fp3Issues[] = [
                    'id'              => $pi->id,
                    'code'            => $pi->code,
                    'current_sub'     => $pi->subtotal,
                    'current_tax'     => $pi->tax_amount,
                    'current_total'   => $pi->total,
                    'calc_sub'        => $calcSubtotal,
                    'calc_tax'        => $calcTax,
                    'calc_total'      => $calcSubtotal + $calcTax,
                ];
            }
        }

        if (empty($fp3Issues)) {
            $this->line('  FP3 ✓ subtotal/tax_amount hóa đơn khớp với tổng tính từ PO items.');
        } else {
            $this->warn("  FP3: " . count($fp3Issues) . " hóa đơn có subtotal/tax không khớp PO items:");
            $this->table(
                ['HĐ', 'Sub hiện tại', 'Thuế hiện tại', 'Sub tính lại', 'Thuế tính lại', 'Chênh sub', 'Chênh thuế'],
                array_map(fn ($r) => [
                    $r['code'],
                    number_format($r['current_sub']),
                    number_format($r['current_tax']),
                    number_format($r['calc_sub']),
                    number_format($r['calc_tax']),
                    number_format($r['calc_sub'] - $r['current_sub']),
                    number_format($r['calc_tax'] - $r['current_tax']),
                ], $fp3Issues)
            );
            $this->warn('  FP3 không tự sửa vì có thể HĐ được nhập tay với số liệu từ hóa đơn gốc NCC.');
            $this->warn('  Kế toán cần kiểm tra thủ công từng hóa đơn này.');
        }

        return $fp1Count + $fp2Rows->count();
    }

    // ─── SALES FIXES ─────────────────────────────────────────────────────────

    private function fixSales(bool $apply, int $limit): int
    {
        $this->info('── FS1: Backfill vat_rate trên order_items từ product.vat_percent ──');

        $rows = DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('p.vat_percent', '>', 0)
            ->where(fn ($q) => $q->whereNull('oi.vat_rate')->orWhere('oi.vat_rate', 0))
            ->whereNotNull('oi.product_id')
            ->select([
                'oi.id as oi_id',
                'o.code as order_code',
                DB::raw('p.code as product_code'),
                DB::raw('p.vat_percent as new_vat_rate'),
            ])
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  FS1 ✓ Không có order item nào cần backfill vat_rate.');
        } else {
            $this->line("  Sẽ cập nhật {$rows->count()} order_items:");
            $this->table(
                ['OI ID', 'Đơn hàng', 'Sản phẩm', 'vat_rate mới'],
                $rows->take(20)->map(fn ($r) => [
                    $r->oi_id, $r->order_code, $r->product_code, $r->new_vat_rate . '%',
                ])->all()
            );
            if ($rows->count() > 20) $this->line('  ... và ' . ($rows->count() - 20) . ' dòng khác.');
            $this->warn('  Lưu ý FS1: Chỉ cập nhật vat_rate cho tham chiếu. Bút toán đã posted không thay đổi.');

            if ($apply) {
                DB::statement("
                    UPDATE order_items
                    SET vat_rate = (SELECT vat_percent FROM products WHERE products.id = order_items.product_id)
                    WHERE product_id IS NOT NULL
                      AND (vat_rate IS NULL OR vat_rate = 0)
                      AND EXISTS (
                          SELECT 1 FROM products WHERE products.id = order_items.product_id AND products.vat_percent > 0
                      )
                ");
                $this->info("  ✓ Đã cập nhật {$rows->count()} order_items.");
            }
        }

        return $rows->count();
    }
}
