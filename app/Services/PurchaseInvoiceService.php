<?php

namespace App\Services;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    private const TRANSITIONS = [
        'pending'        => ['received', 'cancelled'],
        'received'       => ['reviewing', 'cancelled'],
        'reviewing'      => ['valid', 'need_supplement', 'cancelled'],
        'valid'          => ['cancelled'],
        'need_supplement'=> ['reviewing', 'cancelled'],
        'partial_paid'   => ['cancelled'],
        'paid'           => [],
        'cancelled'      => [],
    ];

    public function transition(PurchaseInvoice $invoice, PurchaseInvoiceStatus $newStatus): void
    {
        $allowed = self::TRANSITIONS[$invoice->status->value] ?? [];
        if (!in_array($newStatus->value, $allowed)) {
            throw new \RuntimeException("Không thể chuyển sang trạng thái \"{$newStatus->label()}\".");
        }
        $invoice->update(['status' => $newStatus]);
    }

    public function addPayment(PurchaseInvoice $invoice, array $data): PurchaseInvoicePayment
    {
        if ($invoice->status === PurchaseInvoiceStatus::Cancelled) {
            throw new \RuntimeException('Hóa đơn đã hủy, không thể ghi nhận thanh toán.');
        }

        return DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);

            $this->recalculatePaid($invoice);

            return $payment;
        });
    }

    public function removePayment(PurchaseInvoice $invoice, PurchaseInvoicePayment $payment): void
    {
        $payment->delete();
        $this->recalculatePaid($invoice);
    }

    private function recalculatePaid(PurchaseInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total;

        $status = match(true) {
            $invoice->status === PurchaseInvoiceStatus::Cancelled => $invoice->status,
            $paid <= 0                                             => PurchaseInvoiceStatus::Valid,
            $paid >= $total                                        => PurchaseInvoiceStatus::Paid,
            default                                                => PurchaseInvoiceStatus::PartialPaid,
        };

        $invoice->update([
            'paid_amount' => $paid,
            'status'      => $status,
        ]);
    }
}
