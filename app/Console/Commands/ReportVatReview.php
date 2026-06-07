<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liệt kê sản phẩm có vat_percent = 0 cần kế toán xác nhận.
 *
 * Lý do: products.vat_percent default = 0. Nếu sản phẩm thực tế có VAT đầu vào
 * 8% hoặc 10% nhưng chưa được điền, unit_cogs trong order_items sẽ bằng cost_price
 * (không back-calculate VAT) → COGS báo cáo bị cao hơn thực tế.
 *
 * Sau khi kế toán xác nhận và cập nhật vat_percent, chạy:
 *   php artisan cogs:rebackfill-estimated
 * để re-calculate unit_cogs cho các dòng backfill_estimated.
 */
class ReportVatReview extends Command
{
    protected $signature = 'report:vat-review
                            {--only-with-orders : Chỉ hiển thị sản phẩm có order_items}';

    protected $description = 'Danh sách sản phẩm có vat_percent = 0 cần kế toán rà soát (CẦN KẾ TOÁN XÁC NHẬN)';

    public function handle(): int
    {
        $onlyWithOrders = $this->option('only-with-orders');

        $rows = DB::table('products')
            ->leftJoin(
                DB::raw('(SELECT product_id, COUNT(*) as order_item_count
                           FROM order_items
                           WHERE product_id IS NOT NULL
                           GROUP BY product_id) oi_counts'),
                'oi_counts.product_id', '=', 'products.id'
            )
            ->whereNull('products.deleted_at')
            ->where(fn ($q) => $q->where('products.vat_percent', 0)->orWhereNull('products.vat_percent'))
            ->when($onlyWithOrders, fn ($q) => $q->where('oi_counts.order_item_count', '>', 0))
            ->select([
                'products.id',
                'products.code',
                'products.name',
                DB::raw('ROUND(products.cost_price, 0) as cost_price'),
                DB::raw('COALESCE(products.vat_percent, 0) as vat_percent'),
                DB::raw('COALESCE(oi_counts.order_item_count, 0) as affected_order_items'),
            ])
            ->orderByDesc('oi_counts.order_item_count')
            ->orderBy('products.code')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Không có sản phẩm nào có vat_percent = 0.');
            return self::SUCCESS;
        }

        $this->warn('⚠  CẦN KẾ TOÁN XÁC NHẬN: các sản phẩm dưới đây có vat_percent = 0.');
        $this->line('   Nếu sản phẩm thực tế có VAT đầu vào 8% hoặc 10%, COGS đang bị tính cao hơn thực tế.');
        $this->line('   Sau khi cập nhật vat_percent, chạy: php artisan cogs:rebackfill-estimated');
        $this->newLine();

        $this->table(
            ['ID', 'Mã SP', 'Tên sản phẩm', 'cost_price', 'vat_percent', '# order_items bị ảnh hưởng'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->code,
                mb_strimwidth($r->name, 0, 40, '…'),
                number_format((float)$r->cost_price, 0, ',', '.'),
                $r->vat_percent . '%',
                $r->affected_order_items,
            ])->toArray()
        );

        $this->newLine();
        $this->line('Tổng: ' . $rows->count() . ' sản phẩm, '
            . $rows->sum('affected_order_items') . ' order_items bị ảnh hưởng.');

        return self::SUCCESS;
    }
}
