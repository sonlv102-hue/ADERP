<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuotationService
{
    public function approve(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw new RuntimeException('Chỉ có thể duyệt báo giá ở trạng thái "Đã gửi".');
        }
        $quotation->update(['status' => QuotationStatus::Approved]);
    }

    public function reject(Quotation $quotation): void
    {
        if (!in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Sent])) {
            throw new RuntimeException('Không thể từ chối báo giá ở trạng thái này.');
        }
        $quotation->update(['status' => QuotationStatus::Rejected]);
    }

    public function markSent(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw new RuntimeException('Chỉ có thể gửi báo giá ở trạng thái nháp.');
        }
        $quotation->update(['status' => QuotationStatus::Sent]);
    }

    public function cancel(Quotation $quotation): void
    {
        if (in_array($quotation->status, [QuotationStatus::Cancelled])) {
            throw new RuntimeException('Báo giá đã ở trạng thái hủy.');
        }
        if ($quotation->status === QuotationStatus::Approved && $quotation->orders()->exists()) {
            throw new RuntimeException('Không thể hủy báo giá đã duyệt và đã có đơn hàng liên kết.');
        }
        $quotation->update(['status' => QuotationStatus::Cancelled]);
    }

    public function recall(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw new RuntimeException('Chỉ có thể thu hồi báo giá ở trạng thái "Đã gửi".');
        }
        $quotation->update(['status' => QuotationStatus::Draft]);
    }

    public function unapprove(Quotation $quotation): void
    {
        if ($quotation->status !== QuotationStatus::Approved) {
            throw new RuntimeException('Chỉ có thể hủy duyệt báo giá ở trạng thái "Đã duyệt".');
        }
        if ($quotation->orders()->exists()) {
            throw new RuntimeException('Không thể hủy duyệt báo giá đã có đơn hàng liên kết.');
        }
        $quotation->update(['status' => QuotationStatus::Sent]);
    }

    public function convertToOrder(Quotation $quotation): Order
    {
        if ($quotation->status !== QuotationStatus::Approved) {
            throw new RuntimeException('Chỉ có thể chuyển báo giá đã duyệt thành đơn hàng.');
        }

        return DB::transaction(function () use ($quotation) {
            $order = Order::create([
                'code'         => Order::generateCode(),
                'customer_id'  => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'order_date'   => now()->toDateString(),
                'status'       => OrderStatus::Pending,
                'created_by'   => auth()->id(),
                'notes'        => $quotation->notes,
            ]);

            $sub = $quotation->subtotal();
            $docFactor = $sub > 0 ? $quotation->netBeforeVat() / $sub : 1;

            foreach ($quotation->items as $qItem) {
                $order->items()->create([
                    'product_id' => $qItem->product_id,
                    'service_id' => $qItem->service_id,
                    'name'       => $qItem->name,
                    'unit'       => $qItem->unit,
                    'quantity'   => $qItem->quantity,
                    'unit_price' => round(
                        (float) $qItem->unit_price * (1 - (float) $qItem->discount_percent / 100) * $docFactor,
                        0
                    ),
                    'vat_rate'   => $qItem->vat_rate !== null ? (float) $qItem->vat_rate : null,
                ]);
            }

            return $order;
        });
    }
}
