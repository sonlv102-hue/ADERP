<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StockExit;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Cập nhật delivered_quantity trên order_items sau khi confirm StockExit.
     * Trả về danh sách cảnh báo (nếu có sản phẩm giao vượt số lượng đặt).
     */
    public function syncDelivery(StockExit $exit): array
    {
        if (! $exit->order_id) {
            return [];
        }

        $exit->load('items.product');
        $order = Order::with('items')->find($exit->order_id);

        if (! $order) {
            return [];
        }

        $warnings = [];

        DB::transaction(function () use ($exit, $order, &$warnings) {
            $orderItemsByProduct = $order->items
                ->whereNotNull('product_id')
                ->keyBy('product_id');

            foreach ($exit->items as $exitItem) {
                $orderItem = $orderItemsByProduct[$exitItem->product_id] ?? null;
                if (! $orderItem) {
                    continue;
                }

                $newDelivered = (float) $orderItem->delivered_quantity + (float) $exitItem->quantity;
                $orderItem->update(['delivered_quantity' => $newDelivered]);

                if ($newDelivered > (float) $orderItem->quantity) {
                    $over = $newDelivered - $orderItem->quantity;
                    $warnings[] = "SP \"{$exitItem->product->name}\": đặt {$orderItem->quantity}, đã giao {$newDelivered} (vượt {$over}).";
                }
            }

            // Sync order status
            $order->load('items');
            $allItems = $order->items->whereNotNull('product_id');

            if ($allItems->isEmpty()) {
                return;
            }

            $fullyDelivered = $allItems->every(fn ($i) => (float) $i->delivered_quantity >= (float) $i->quantity);
            $anyDelivered   = $allItems->some(fn ($i) => (float) $i->delivered_quantity > 0);

            $newStatus = match (true) {
                $fullyDelivered => OrderStatus::Completed,
                $anyDelivered   => OrderStatus::PartialDelivered,
                default         => $order->status,
            };

            if ($newStatus !== $order->status) {
                $order->update(['status' => $newStatus]);
            }
        });

        return $warnings;
    }
}
