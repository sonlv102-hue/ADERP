<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemOrderItemAllocation;
use RuntimeException;

class PurchaseOrderAllocationService
{
    public function allocate(PurchaseOrderItem $poItem, OrderItem $orderItem, float $qty): PurchaseOrderItemOrderItemAllocation
    {
        if ($qty <= 0) {
            throw new RuntimeException('Số lượng phân bổ phải lớn hơn 0.');
        }

        $poItem->loadMissing('product', 'purchaseOrder');
        $orderItem->loadMissing('order');

        $isLinked = $poItem->purchaseOrder->orders()->where('orders.id', $orderItem->order_id)->exists();
        if (!$isLinked) {
            throw new RuntimeException('Đơn mua hàng chưa liên kết với đơn hàng bán này.');
        }

        if ($poItem->product_id !== $orderItem->product_id) {
            throw new RuntimeException('Chỉ có thể phân bổ giữa 2 dòng hàng cùng sản phẩm.');
        }

        $productUnit = $poItem->product?->unit;
        if (!empty($orderItem->unit) && $productUnit && $orderItem->unit !== $productUnit) {
            throw new RuntimeException("Đơn vị tính không khớp: đơn mua ({$productUnit}) khác đơn bán ({$orderItem->unit}).");
        }

        $poRemaining = $poItem->quantity - $poItem->allocatedQuantity();
        if ($qty > $poRemaining) {
            throw new RuntimeException("Vượt quá số lượng còn lại của dòng đơn mua ({$poRemaining}).");
        }

        $orderRemaining = $orderItem->quantity - $orderItem->allocatedQuantity();
        if ($qty > $orderRemaining) {
            throw new RuntimeException("Vượt quá số lượng còn lại của dòng đơn bán ({$orderRemaining}).");
        }

        return PurchaseOrderItemOrderItemAllocation::create([
            'purchase_order_item_id' => $poItem->id,
            'order_item_id'          => $orderItem->id,
            'purchase_order_id'      => $poItem->purchase_order_id,
            'order_id'               => $orderItem->order_id,
            'allocated_qty'          => $qty,
        ]);
    }

    public function void(PurchaseOrderItemOrderItemAllocation $allocation): void
    {
        if ($allocation->voided_at) {
            return;
        }

        $allocation->update(['voided_at' => now()]);
    }
}
