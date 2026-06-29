<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StockExit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateDeliveredQtyCommand extends Command
{
    protected $signature = 'sales-orders:recalculate-delivered-qty
                            {--order= : Mã đơn hàng (code). Bỏ trống = tất cả đơn}
                            {--dry-run : Chỉ in kết quả, không lưu}
                            {--apply : Áp dụng thay đổi vào DB}';

    protected $description = 'Tính lại delivered_quantity từ stock_exit_items hợp lệ và cập nhật trạng thái đơn hàng.';

    private bool $dryRun = true;

    public function handle(): int
    {
        $this->dryRun = ! $this->option('apply');
        if ($this->dryRun) {
            $this->info('[DRY RUN] Không có thay đổi nào được lưu. Thêm --apply để áp dụng.');
        }

        $code = $this->option('order');
        $query = Order::with(['items'])->orderByDesc('id');
        if ($code) {
            $query->where('code', $code);
        }
        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->error("Không tìm thấy đơn hàng" . ($code ? " {$code}" : '') . ".");
            return 1;
        }

        $totalFixed = 0;
        foreach ($orders as $order) {
            $changed = $this->recalculate($order);
            $totalFixed += $changed;
        }

        $this->newLine();
        $label = $this->dryRun ? '[DRY RUN] Sẽ sửa' : 'Đã sửa';
        $this->info("{$label}: {$totalFixed} order item(s).");
        return 0;
    }

    private function recalculate(Order $order): int
    {
        // Lấy các phiếu xuất confirmed liên kết với đơn
        $exits = StockExit::where('order_id', $order->id)
            ->whereIn('status', ['confirmed', 'posted', 'delivered'])
            ->with('items')
            ->get();

        // Tổng hợp qty đã giao theo order_item_id (ưu tiên) và product_id (fallback)
        $deliveredByItemId   = [];
        $deliveredByProduct  = [];

        foreach ($exits as $exit) {
            foreach ($exit->items as $ei) {
                $qty = (float) $ei->quantity;
                if ($ei->order_item_id) {
                    $deliveredByItemId[$ei->order_item_id] = ($deliveredByItemId[$ei->order_item_id] ?? 0) + $qty;
                } elseif ($ei->product_id) {
                    $deliveredByProduct[$ei->product_id] = ($deliveredByProduct[$ei->product_id] ?? 0) + $qty;
                }
            }
        }

        $changed = 0;
        foreach ($order->items as $item) {
            $computedDelivered = $deliveredByItemId[$item->id]
                ?? $deliveredByProduct[$item->product_id]
                ?? 0.0;

            $current = (float) $item->delivered_quantity;
            if (abs($computedDelivered - $current) > 0.001) {
                $this->line("  {$order->code} / item #{$item->id} [{$item->name}]: {$current} → {$computedDelivered}");
                if (! $this->dryRun) {
                    $item->update(['delivered_quantity' => $computedDelivered]);
                }
                $changed++;
            }
        }

        if ($changed > 0 && ! $this->dryRun) {
            $this->syncStatus($order->fresh('items'));
        }

        return $changed;
    }

    private function syncStatus(Order $order): void
    {
        $items = $order->items->whereNotNull('product_id');
        if ($items->isEmpty()) return;

        $fullyDelivered = $items->every(fn ($i) => (float) $i->delivered_quantity >= (float) $i->quantity);
        $anyDelivered   = $items->some(fn ($i) => (float) $i->delivered_quantity > 0);

        $newStatus = match (true) {
            $fullyDelivered => OrderStatus::Completed,
            $anyDelivered   => OrderStatus::PartialDelivered,
            default         => OrderStatus::Processing,
        };

        if ($newStatus !== $order->status) {
            $this->line("  {$order->code}: status {$order->status->value} → {$newStatus->value}");
            $order->update(['status' => $newStatus]);
        }
    }
}
