<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\SalesReturnStatus;
use App\Enums\SerialStatus;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesReturnService
{
    public function confirmReturn(SalesReturn $return): void
    {
        if ($return->status !== SalesReturnStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        $return->load('items.orderItem', 'items.product', 'items.serials');

        foreach ($return->items as $item) {
            $orderItem = $item->orderItem;
            if (! $orderItem) {
                throw new RuntimeException("Không tìm thấy dòng đơn hàng cho sản phẩm [{$item->product->name}].");
            }

            $priorReturned = SalesReturnItem::whereHas(
                'salesReturn',
                fn ($q) => $q->where('status', SalesReturnStatus::Confirmed)
                             ->where('id', '!=', $return->id)
            )->where('order_item_id', $item->order_item_id)->sum('quantity');

            $maxReturnable = (float) $orderItem->delivered_quantity - (float) $priorReturned;

            if ((float) $item->quantity > $maxReturnable) {
                throw new RuntimeException(
                    "Sản phẩm \"{$item->product->name}\": số lượng trả ({$item->quantity}) vượt quá tối đa có thể trả ({$maxReturnable})."
                );
            }

            // Validate serial count for serial-tracked products
            if ($item->product->has_serial) {
                $serialCount = $item->serials->count();
                if ($serialCount !== (int) $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm \"{$item->product->name}\": cần chọn đúng {$item->quantity} serial (hiện có {$serialCount})."
                    );
                }
                foreach ($item->serials as $serial) {
                    if ($serial->status !== SerialStatus::Sold) {
                        throw new RuntimeException("Serial [{$serial->serial_number}] không ở trạng thái đã bán.");
                    }
                }
            }
        }

        DB::transaction(function () use ($return) {
            foreach ($return->items as $item) {
                // Create stock movement IN
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'in',
                    'quantity'    => $item->quantity,
                    'source_type' => SalesReturn::class,
                    'source_id'   => $return->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Trả hàng bán {$return->code}",
                ]);

                // Transition serials back to in_stock and link to this return item
                foreach ($item->serials as $serial) {
                    $serial->update([
                        'warehouse_id'         => $return->warehouse_id,
                        'sales_return_item_id' => $item->id,
                    ]);
                    $serial->transition(SerialStatus::InStock);
                }

                // Reduce delivered_quantity on order item
                $orderItem = $item->orderItem;
                $newDelivered = max(0, (float) $orderItem->delivered_quantity - (float) $item->quantity);
                $orderItem->update(['delivered_quantity' => $newDelivered]);
            }

            // Sync order status
            $this->syncOrderStatus($return->order_id);

            $return->update(['status' => SalesReturnStatus::Confirmed]);
        });
    }

    public function cancelReturn(SalesReturn $return): void
    {
        if ($return->status === SalesReturnStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        if ($return->status === SalesReturnStatus::Draft) {
            $return->update(['status' => SalesReturnStatus::Cancelled]);
            return;
        }

        // Confirmed: reverse stock and restore delivered_quantity
        $return->load('items.orderItem', 'items.product', 'items.serials');

        DB::transaction(function () use ($return) {
            foreach ($return->items as $item) {
                // Reversal movement OUT
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -$item->quantity,
                    'source_type' => SalesReturn::class,
                    'source_id'   => $return->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy phiếu trả hàng bán {$return->code}",
                ]);

                // Transition serials back to sold and unlink return
                foreach ($item->serials as $serial) {
                    $serial->update(['sales_return_item_id' => null]);
                    $serial->transition(SerialStatus::Sold);
                }

                // Restore delivered_quantity
                $orderItem = $item->orderItem;
                $restored  = (float) $orderItem->delivered_quantity + (float) $item->quantity;
                $orderItem->update(['delivered_quantity' => $restored]);
            }

            $this->syncOrderStatus($return->order_id);

            $return->update(['status' => SalesReturnStatus::Cancelled]);
        });
    }

    private function syncOrderStatus(int $orderId): void
    {
        $order = \App\Models\Order::with('items')->find($orderId);
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
    }
}
