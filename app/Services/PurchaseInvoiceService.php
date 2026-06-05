<?php

namespace App\Services;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(private AccountingService $accounting) {}

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
        if (!in_array($invoice->status, [
            PurchaseInvoiceStatus::Valid,
            PurchaseInvoiceStatus::PartialPaid,
        ])) {
            throw new \RuntimeException('Chỉ có thể ghi nhận thanh toán khi hóa đơn ở trạng thái Hợp lệ hoặc TT một phần.');
        }

        $payment = DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);

            $this->recalculatePaid($invoice);
            $this->postPaymentEntry($payment, $invoice);

            return $payment;
        });

        return $payment;
    }

    public function removePayment(PurchaseInvoice $invoice, PurchaseInvoicePayment $payment): void
    {
        DB::transaction(function () use ($invoice, $payment) {
            try {
                $this->accounting->reverseOrDelete('purchase_invoice_payment', $payment->id, "Trả NCC {$invoice->code}");
            } catch (\Exception $e) {
                \Log::warning("Reverse purchase payment entry failed [{$invoice->code}]: " . $e->getMessage());
            }

            $payment->delete();
            $this->recalculatePaid($invoice);
        });
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function postPaymentEntry(PurchaseInvoicePayment $payment, PurchaseInvoice $invoice): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) return;

        $cashAccount = match($payment->method) {
            'bank_transfer', 'bank' => '112',
            default                 => '111',
        };

        try {
            $this->accounting->post(
                "Trả tiền NCC {$invoice->code}",
                Carbon::parse($payment->payment_date),
                [
                    ['account' => '331',        'debit' => (int) $amount, 'credit' => 0,
                     'description' => "Xóa công nợ NCC - {$invoice->code}"],
                    ['account' => $cashAccount, 'debit' => 0, 'credit' => (int) $amount,
                     'description' => "Trả tiền NCC - {$invoice->code}"],
                ],
                'purchase_invoice_payment', $payment->id, true
            );
        } catch (\Exception $e) {
            \Log::warning("Auto-posting failed [PurchasePayment {$invoice->code}]: " . $e->getMessage());
        }
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
