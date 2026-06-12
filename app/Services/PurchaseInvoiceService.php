<?php

namespace App\Services;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if ($payment->isVoided()) {
            throw new \RuntimeException('Khoản thanh toán này đã bị thu hồi trước đó.');
        }

        DB::transaction(function () use ($invoice, $payment) {
            $this->accounting->reverseOrDelete('purchase_invoice_payment', $payment->id, "Thu hồi thanh toán {$invoice->code}");
            $payment->update([
                'status'     => 'voided',
                'void_reason'=> 'Xóa từng khoản',
                'voided_by'  => auth()->id(),
                'voided_at'  => now(),
            ]);
            $this->recalculatePaid($invoice);
        });
    }

    /**
     * Thu hồi toàn bộ thanh toán của một hóa đơn.
     * - Đảo JE từng khoản (hoặc xóa nếu còn draft)
     * - Đánh dấu voided (không xóa record)
     * - Reset trạng thái hóa đơn về valid
     */
    public function recallPayments(PurchaseInvoice $invoice, string $reason): int
    {
        $allowedStatuses = [
            PurchaseInvoiceStatus::Paid,
            PurchaseInvoiceStatus::PartialPaid,
        ];

        if (!in_array($invoice->status, $allowedStatuses)) {
            throw new \RuntimeException('Chỉ thu hồi thanh toán được hóa đơn đã thanh toán hoặc thanh toán một phần.');
        }

        $activePayments = $invoice->payments()->active()->get();
        if ($activePayments->isEmpty()) {
            throw new \RuntimeException('Hóa đơn này không có khoản thanh toán nào để thu hồi.');
        }

        DB::transaction(function () use ($invoice, $activePayments, $reason) {
            $userId = auth()->id();

            foreach ($activePayments as $payment) {
                $this->accounting->reverseOrDelete(
                    'purchase_invoice_payment',
                    $payment->id,
                    "Thu hồi thanh toán {$invoice->code}: {$reason}"
                );
                $payment->update([
                    'status'      => 'voided',
                    'void_reason' => $reason,
                    'voided_by'   => $userId,
                    'voided_at'   => now(),
                ]);
            }

            $invoice->update([
                'paid_amount' => 0,
                'status'      => PurchaseInvoiceStatus::Valid,
            ]);

            Log::info("PurchaseInvoice #{$invoice->id} ({$invoice->code}): recall {$activePayments->count()} payments by user {$userId}. Reason: {$reason}");
        });

        return $activePayments->count();
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function postPaymentEntry(PurchaseInvoicePayment $payment, PurchaseInvoice $invoice): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) return;

        $cashAccount    = match($payment->method) {
            'bank_transfer', 'bank' => '1121',
            default                 => '1111',
        };
        $invoice->loadMissing('supplier');
        $payableAccount = $invoice->supplier->getPayableAccount();

        $this->accounting->tryPost(
            "Trả tiền NCC {$invoice->code}",
            Carbon::parse($payment->payment_date),
            [
                ['account' => $payableAccount, 'debit' => (int) $amount, 'credit' => 0,
                 'description'  => "Xóa công nợ NCC - {$invoice->code}",
                 'partner_type' => 'supplier', 'partner_id' => $invoice->supplier_id],
                ['account' => $cashAccount,    'debit' => 0, 'credit' => (int) $amount,
                 'description' => "Trả tiền NCC - {$invoice->code}"],
            ],
            'purchase_invoice_payment', $payment->id, 'payment'
        );
    }

    private function recalculatePaid(PurchaseInvoice $invoice): void
    {
        $paid = (float) $invoice->payments()->active()->sum('amount');
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
