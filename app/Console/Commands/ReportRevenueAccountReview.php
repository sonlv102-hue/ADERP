<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liệt kê các đối tượng thiếu cấu hình revenue_account_code:
 *   - Sản phẩm không có mapping rõ ràng (product null + category null + item_type không phải goods/service)
 *   - Standalone invoice (không có order_id) thiếu revenue_account_code
 *   - order_items còn revenue_account_code = null
 *
 * Dùng sau khi cập nhật products.item_type / products.revenue_account_code
 * để xác nhận tất cả sản phẩm đã được mapping trước khi phát hành hóa đơn.
 */
class ReportRevenueAccountReview extends Command
{
    protected $signature = 'report:revenue-account-review';

    protected $description = 'Danh sách sản phẩm/invoice thiếu cấu hình revenue_account_code (CẦN KẾ TOÁN XÁC NHẬN)';

    public function handle(): int
    {
        $this->section1Products();
        $this->section2StandaloneInvoices();
        $this->section3OrderItems();

        return self::SUCCESS;
    }

    private function section1Products(): void
    {
        // Sản phẩm có item_type không phải goods/service VÀ không có mapping tường minh
        $rows = DB::table('products as p')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin(
                DB::raw('(SELECT product_id, COUNT(*) as order_item_count
                           FROM order_items WHERE product_id IS NOT NULL GROUP BY product_id) oi'),
                'oi.product_id', '=', 'p.id'
            )
            ->whereNull('p.deleted_at')
            ->where(function ($q) {
                // Thiếu mapping tường minh ở product-level
                $q->whereNull('p.revenue_account_code')
                  ->whereNull('pc.revenue_account_code')
                  // VÀ item_type không map được
                  ->whereNotIn('p.item_type', ['goods', 'service']);
            })
            ->select([
                'p.id',
                'p.code',
                'p.name',
                'p.item_type',
                DB::raw('COALESCE(p.revenue_account_code, pc.revenue_account_code) as effective_account'),
                DB::raw('COALESCE(oi.order_item_count, 0) as order_item_count'),
            ])
            ->orderByDesc('oi.order_item_count')
            ->orderBy('p.code')
            ->get();

        $this->newLine();
        $this->warn('=== PHẦN 1: Sản phẩm thiếu revenue_account_code ===');
        $this->line('   (item_type không phải goods/service VÀ không có cấu hình tường minh)');

        if ($rows->isEmpty()) {
            $this->info('   ✓ Không có sản phẩm nào cần xem xét.');
            return;
        }

        $this->table(
            ['ID', 'Mã SP', 'Tên sản phẩm', 'item_type', 'effective_account', '# order_items'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->code,
                mb_strimwidth($r->name, 0, 40, '…'),
                $r->item_type ?? '(null)',
                $r->effective_account ?? '(chưa có)',
                $r->order_item_count,
            ])->toArray()
        );
        $this->line('Giải pháp: cập nhật products.item_type = goods/service, HOẶC set products.revenue_account_code / product_categories.revenue_account_code.');
    }

    private function section2StandaloneInvoices(): void
    {
        $rows = DB::table('invoices')
            ->whereNull('order_id')
            ->whereNull('revenue_account_code')
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->select(['id', 'code', 'issue_date', 'status', 'subtotal', 'total'])
            ->orderByDesc('id')
            ->get();

        $this->newLine();
        $this->warn('=== PHẦN 2: Standalone invoice thiếu revenue_account_code ===');
        $this->line('   (invoice không gắn order; khi hạch toán sẽ fallback về 5111 + log warning)');

        if ($rows->isEmpty()) {
            $this->info('   ✓ Không có hóa đơn nào cần xem xét.');
            return;
        }

        $this->table(
            ['ID', 'Mã HĐ', 'Ngày phát hành', 'Trạng thái', 'Subtotal', 'Total'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->code,
                $r->issue_date,
                $r->status,
                number_format((float) $r->subtotal, 0, ',', '.'),
                number_format((float) $r->total, 0, ',', '.'),
            ])->toArray()
        );
        $this->line('Giải pháp: mở form sửa hóa đơn và chọn Tài khoản doanh thu phù hợp.');
    }

    private function section3OrderItems(): void
    {
        $count = DB::table('order_items')
            ->whereNull('revenue_account_code')
            ->whereNotNull('product_id')
            ->count();

        $this->newLine();
        $this->warn('=== PHẦN 3: order_items còn revenue_account_code = null ===');

        if ($count === 0) {
            $this->info('   ✓ Tất cả order_items có product_id đều đã có revenue_account_code.');
            return;
        }

        $this->line("   Có {$count} order_items với product_id nhưng revenue_account_code = null.");
        $this->line('   Nguyên nhân: sản phẩm có item_type không phải goods/service và không có override.');
        $this->line('   Giải pháp: cập nhật products.item_type hoặc products.revenue_account_code,');
        $this->line('   rồi tạo lại đơn hàng hoặc dùng tinker để backfill.');
    }
}
