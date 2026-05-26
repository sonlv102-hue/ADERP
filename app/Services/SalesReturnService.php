<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\SalesReturnStatus;
use App\Enums\SerialStatus;
use App\Models\OrderOverDelivery;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SalesReturnService
{
    public function __construct(private OrderService $orderService) {}
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
                $orderItem  = $item->orderItem;
                $orderedQty = (float) $orderItem->quantity;
                $newDelivered = max(0, (float) $orderItem->delivered_quantity - (float) $item->quantity);
                $orderItem->update(['delivered_quantity' => $newDelivered]);

                // Resolve or shrink over-delivery alert for this product
                $this->syncOverDeliveryAlert($return->order_id, $item->product_id, $newDelivered, $orderedQty);
            }

            // Sync order status via shared OrderService method
            $this->orderService->syncOrderStatus($return->order_id);

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
                $orderItem  = $item->orderItem;
                $orderedQty = (float) $orderItem->quantity;
                $restored   = (float) $orderItem->delivered_quantity + (float) $item->quantity;
                $orderItem->update(['delivered_quantity' => $restored]);

                // Re-create over-delivery alert if cancellation pushes qty back above ordered
                if ($restored > $orderedQty) {
                    $this->reRecordOverDelivery(
                        $return->order_id,
                        $item->product_id,
                        $item->product->name,
                        $restored - $orderedQty
                    );
                }
            }

            $this->orderService->syncOrderStatus($return->order_id);

            $return->update(['status' => SalesReturnStatus::Cancelled]);
        });
    }

    private function syncOverDeliveryAlert(int $orderId, int $productId, float $newDelivered, float $orderedQty): void
    {
        $alert = OrderOverDelivery::where('order_id', $orderId)
            ->where('product_id', $productId)
            ->whereNull('resolved_at')
            ->first();

        if (! $alert) {
            return;
        }

        if ($newDelivered <= $orderedQty) {
            $alert->update(['resolved_at' => now()]);
        } else {
            $alert->update(['over_quantity' => $newDelivered - $orderedQty]);
        }
    }

    private function reRecordOverDelivery(int $orderId, int $productId, string $productName, float $over): void
    {
        $existing = OrderOverDelivery::where('order_id', $orderId)
            ->where('product_id', $productId)
            ->whereNull('resolved_at')
            ->first();

        if ($existing) {
            $existing->update(['over_quantity' => $over]);
        } else {
            OrderOverDelivery::create([
                'order_id'      => $orderId,
                'product_id'    => $productId,
                'product_name'  => $productName,
                'over_quantity' => $over,
            ]);
        }
    }
}
