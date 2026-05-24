<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use RuntimeException;

class PurchaseOrderService
{
    public function send(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw new RuntimeException('Chỉ có thể gửi đơn ở trạng thái nháp.');
        }

        $po->update(['status' => PurchaseOrderStatus::Sent]);
    }

    public function cancel(PurchaseOrder $po): void
    {
        if (in_array($po->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::PartialReceived])) {
            throw new RuntimeException('Không thể hủy đơn đã bắt đầu nhận hàng.');
        }

        $po->update(['status' => PurchaseOrderStatus::Cancelled]);
    }

    public function syncReceiveStatus(PurchaseOrder $po): void
    {
        $validStatuses = [
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartialReceived,
            PurchaseOrderStatus::Received,
        ];

        if (!in_array($po->status, $validStatuses)) {
            return;
        }

        $po->load('items');

        $confirmedEntryIds = StockEntry::where('purchase_order_id', $po->id)
            ->where('status', StockEntryStatus::Confirmed)
            ->pluck('id');

        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $totalOrdered  = $po->items->sum('quantity');
        $totalReceived = $po->items->sum(fn ($item) => (int) ($receivedQtys[$item->product_id] ?? 0));

        if ($totalReceived === 0) {
            $po->update(['status' => PurchaseOrderStatus::Sent]);
        } elseif ($totalReceived < $totalOrdered) {
            $po->update(['status' => PurchaseOrderStatus::PartialReceived]);
        } else {
            $po->update(['status' => PurchaseOrderStatus::Received]);
        }
    }
}
