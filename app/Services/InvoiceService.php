<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;

class InvoiceService
{
    public function markSent(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new \RuntimeException('Chỉ có thể gửi hóa đơn ở trạng thái Nháp.');
        }
        $invoice->update(['status' => InvoiceStatus::Sent]);
    }

    public function markPaid(Invoice $invoice): void
    {
        if (!in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue])) {
            throw new \RuntimeException('Chỉ có thể đánh dấu thanh toán cho hóa đơn đã gửi hoặc quá hạn.');
        }
        $invoice->update(['status' => InvoiceStatus::Paid]);
    }

    public function markOverdue(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw new \RuntimeException('Chỉ có thể đánh dấu quá hạn cho hóa đơn đã gửi.');
        }
        $invoice->update(['status' => InvoiceStatus::Overdue]);
    }

    public function addPayment(Invoice $invoice, array $data): Payment
    {
        $payment = $invoice->payments()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        // Auto-mark paid if fully settled
        if ($invoice->amountPaid() >= (float) $invoice->total
            && in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue])) {
            $invoice->update(['status' => InvoiceStatus::Paid]);
        }

        return $payment;
    }
}
