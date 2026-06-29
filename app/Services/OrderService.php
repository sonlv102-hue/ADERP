<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderOverDelivery;
use App\Models\StockExit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    /**
     * Cập nhật delivered_quantity sau khi confirm StockExit.
     * Tạo OrderOverDelivery record nếu vượt số lượng đặt.
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
            $orderItemsById      = $order->items->whereNotNull('product_id')->keyBy('id');
            $orderItemsByProduct = $order->items->whereNotNull('product_id')->keyBy('product_id');

            foreach ($exit->items as $exitItem) {
                // Ưu tiên match theo order_item_id (chính xác), fallback về product_id (legacy)
                $orderItem = ($exitItem->order_item_id && isset($orderItemsById[$exitItem->order_item_id]))
                    ? $orderItemsById[$exitItem->order_item_id]
                    : ($orderItemsByProduct[$exitItem->product_id] ?? null);

                if (! $orderItem) {
                    continue;
                }

                $newDelivered = (float) $orderItem->delivered_quantity + (float) $exitItem->quantity;
                $orderItem->update(['delivered_quantity' => $newDelivered]);

                if ($newDelivered > (float) $orderItem->quantity) {
                    $over = $newDelivered - $orderItem->quantity;
                    $warnings[] = "SP \"{$exitItem->product->name}\": đặt {$orderItem->quantity}, đã giao {$newDelivered} (vượt {$over}).";
                    $this->recordOverDelivery($order->id, $exitItem->product_id, $exitItem->product->name, $over);
                }
            }

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

            // Khi đơn bổ sung hoàn thành → tự động giải quyết cảnh báo vượt của cùng khách
            if ($newStatus === OrderStatus::Completed) {
                $this->resolveOverDeliveriesForOrder($order);
            }
        });

        return $warnings;
    }

    /**
     * Khôi phục delivered_quantity khi hủy phiếu xuất bán.
     * Gọi trong cancelExit() sau khi transaction kho đã xong.
     */
    public function reverseDelivery(StockExit $exit): void
    {
        if (! $exit->order_id) {
            return;
        }

        $exit->load('items');
        $order = Order::with('items')->find($exit->order_id);
        if (! $order) {
            return;
        }

        DB::transaction(function () use ($exit, $order) {
            $orderItemsById      = $order->items->whereNotNull('product_id')->keyBy('id');
            $orderItemsByProduct = $order->items->whereNotNull('product_id')->keyBy('product_id');

            foreach ($exit->items as $exitItem) {
                $orderItem = ($exitItem->order_item_id && isset($orderItemsById[$exitItem->order_item_id]))
                    ? $orderItemsById[$exitItem->order_item_id]
                    : ($orderItemsByProduct[$exitItem->product_id] ?? null);

                if (! $orderItem) {
                    continue;
                }
                $newDelivered = max(0.0, (float) $orderItem->delivered_quantity - (float) $exitItem->quantity);
                $orderItem->update(['delivered_quantity' => $newDelivered]);

                Log::info("reverseDelivery: order_item #{$orderItem->id} {$exitItem->quantity} → delivered back to {$newDelivered}");
            }

            $this->syncOrderStatus($order->id);
        });
    }

    private function recordOverDelivery(int $orderId, int $productId, string $productName, float $over): void
    {
        $existing = OrderOverDelivery::where('order_id', $orderId)
            ->where('product_id', $productId)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            $existing->update(['over_quantity' => $over, 'product_name' => $productName]);
        } else {
            OrderOverDelivery::create([
                'order_id'     => $orderId,
                'product_id'   => $productId,
                'product_name' => $productName,
                'over_quantity' => $over,
            ]);
        }
    }

    /**
     * Đồng bộ trạng thái đơn hàng dựa trên delivered_quantity của từng item.
     * Dùng chung cho OrderService và SalesReturnService.
     */
    public function syncOrderStatus(int $orderId): void
    {
        $order = Order::with('items')->find($orderId);
        if (! $order) {
            return;
        }

        $allItems = $order->items->whereNotNull('product_id');
        if ($allItems->isEmpty()) {
            return;
        }

        $fullyDelivered = $allItems->every(fn ($i) => (float) $i->delivered_quantity >= (float) $i->quantity);
        $anyDelivered   = $allItems->some(fn ($i) => (float) $i->delivered_quantity > 0);

        $newStatus = match (true) {
            $fullyDelivered => OrderStatus::Completed,
            $anyDelivered   => OrderStatus::PartialDelivered,
            default         => OrderStatus::Processing,
        };

        if ($newStatus !== $order->status) {
            $order->update(['status' => $newStatus]);
        }

        // Khi đơn bổ sung hoàn thành → tự động giải quyết cảnh báo vượt của cùng khách
        if ($newStatus === OrderStatus::Completed) {
            $this->resolveOverDeliveriesForOrder($order->fresh('items'));
        }
    }

    private function resolveOverDeliveriesForOrder(Order $completedOrder): void
    {
        $productIds = $completedOrder->items->pluck('product_id')->filter()->unique();

        if ($productIds->isEmpty()) {
            return;
        }

        // Explicit link: chỉ resolve alert của đúng đơn gốc
        if ($completedOrder->supplementary_for_order_id) {
            OrderOverDelivery::whereNull('resolved_at')
                ->whereIn('product_id', $productIds)
                ->where('order_id', $completedOrder->supplementary_for_order_id)
                ->update([
                    'resolved_by_order_id' => $completedOrder->id,
                    'resolved_at'          => now(),
                ]);
            return;
        }

        // Fallback heuristic: cùng khách hàng, cùng sản phẩm
        $affectedOrderIds = Order::where('customer_id', $completedOrder->customer_id)
            ->where('id', '!=', $completedOrder->id)
            ->pluck('id');

        if ($affectedOrderIds->isEmpty()) {
            return;
        }

        OrderOverDelivery::whereNull('resolved_at')
            ->whereIn('product_id', $productIds)
            ->whereIn('order_id', $affectedOrderIds)
            ->update([
                'resolved_by_order_id' => $completedOrder->id,
                'resolved_at'          => now(),
            ]);
    }
}
