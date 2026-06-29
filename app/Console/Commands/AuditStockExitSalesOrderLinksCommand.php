<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\StockExit;
use App\Models\StockExitItem;
use Illuminate\Console\Command;

class AuditStockExitSalesOrderLinksCommand extends Command
{
    protected $signature = 'stock-exits:audit-sales-order-links
                            {--order= : Mã đơn hàng (code) cần kiểm tra}';

    protected $description = 'Kiểm tra phiếu xuất kho thiếu liên kết với đơn hàng bán.';

    public function handle(): int
    {
        $code = $this->option('order');

        $exitQuery = StockExit::with(['items.product', 'order'])
            ->whereNotNull('order_id')
            ->orderByDesc('id');

        if ($code) {
            $order = Order::where('code', $code)->first();
            if (! $order) {
                $this->error("Không tìm thấy đơn hàng {$code}.");
                return 1;
            }
            $exitQuery->where('order_id', $order->id);
        }

        $exits = $exitQuery->get();

        $issues = 0;
        foreach ($exits as $exit) {
            $itemsNoLink = $exit->items->filter(fn ($ei) => ! $ei->order_item_id && $ei->product_id);
            $hasDraftExit = $exit->status === 'draft';

            if ($itemsNoLink->isEmpty() && ! $hasDraftExit) continue;

            $issues++;
            $this->newLine();
            $this->warn("Phiếu {$exit->code} | order={$exit->order?->code} | status={$exit->status}");

            if ($hasDraftExit) {
                $this->line("  ⚠ Phiếu DRAFT — chưa confirmed");
            }

            foreach ($itemsNoLink as $ei) {
                $this->line("  ✗ Item #{$ei->id} [{$ei->product?->code}]: order_item_id=null — không thể sync delivered_qty");
            }

            // Kiểm tra product có khớp với đơn hàng không
            $orderProducts = $exit->order?->items->pluck('product_id')->toArray() ?? [];
            foreach ($exit->items as $ei) {
                if ($ei->product_id && ! in_array($ei->product_id, $orderProducts)) {
                    $this->warn("  ✗ Item [{$ei->product?->code}] không có trong đơn hàng — không match được");
                }
            }
        }

        if ($issues === 0) {
            $this->info("✓ Không phát hiện vấn đề liên kết phiếu xuất" . ($code ? " cho {$code}" : '') . ".");
        } else {
            $this->newLine();
            $this->warn("Tổng: {$issues} phiếu có vấn đề. Chạy: php artisan sales-orders:recalculate-delivered-qty [--order=...] --dry-run để xem tác động.");
        }
        $this->newLine();
        return 0;
    }
}
