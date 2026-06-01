<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private AccountingService $accounting) {}

    public function cancel(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            throw new \RuntimeException('Không thể hủy hóa đơn đã thanh toán.');
        }
        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new \RuntimeException('Hóa đơn đã được hủy trước đó.');
        }
        if ($invoice->payments()->exists()) {
            throw new \RuntimeException('Không thể hủy hóa đơn đã có thanh toán. Vui lòng xóa các khoản thanh toán trước.');
        }

        DB::transaction(function () use ($invoice) {
            // Đảo bút toán hóa đơn nếu đã hạch toán
            $entry = JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $invoice->id)
                ->where('status', 'posted')
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->first();

            if ($entry) {
                try {
                    $this->accounting->reverse($entry, "Đảo: Hủy hóa đơn {$invoice->code}");
                } catch (\Exception $e) {
                    \Log::warning("Reverse invoice entry failed [{$invoice->code}]: " . $e->getMessage());
                }
            }

            $invoice->update(['status' => InvoiceStatus::Cancelled]);
        });
    }

    public function markSent(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new \RuntimeException('Chỉ có thể gửi hóa đơn ở trạng thái Nháp.');
        }
        $invoice->update(['status' => InvoiceStatus::Sent]);

        // Hạch toán: Dr 131 / Cr 5111|5113 + Cr 33311
        $this->postInvoiceEntry($invoice);
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
        $payment = DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);

            // Auto-mark paid if fully settled
            $paid = (float) $invoice->payments()->sum('amount');
            if ($paid >= (float) $invoice->total
                && in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue])) {
                $invoice->update(['status' => InvoiceStatus::Paid]);
            }

            return $payment;
        });

        // Hạch toán thanh toán: Dr 111/112 / Cr 131 (ngoài transaction — lỗi kế toán không roll back payment)
        $this->postPaymentEntry($payment, $invoice);

        return $payment;
    }

    public function removePayment(Invoice $invoice, Payment $payment): void
    {
        DB::transaction(function () use ($invoice, $payment) {
            // Đảo bút toán thanh toán nếu đã hạch toán
            $entry = JournalEntry::where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->where('status', 'posted')
                ->first();

            if ($entry) {
                try {
                    $this->accounting->reverse($entry, "Đảo: Thu tiền {$invoice->code}");
                } catch (\Exception $e) {
                    \Log::warning("Reverse payment entry failed [{$invoice->code}]: " . $e->getMessage());
                }
            }

            $payment->delete();

            // Nếu hóa đơn đang là Paid, hoàn về Sent/Overdue
            if ($invoice->status === InvoiceStatus::Paid) {
                $newStatus = $invoice->due_date && now()->gt($invoice->due_date)
                    ? InvoiceStatus::Overdue
                    : InvoiceStatus::Sent;
                $invoice->update(['status' => $newStatus]);
            }
        });
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function postInvoiceEntry(Invoice $invoice): void
    {
        // Ngăn hạch toán trùng — kiểm tra journal entry đã tồn tại cho hóa đơn này
        $alreadyPosted = JournalEntry::where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->where('status', 'posted')
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->exists();
        if ($alreadyPosted) return;

        $subtotal = (float) $invoice->subtotal;
        $tax      = (float) $invoice->tax_amount;
        $total    = (float) $invoice->total;

        if ($total <= 0) return;

        $lines = [
            ['account' => '131', 'debit' => $total, 'credit' => 0,
             'description' => "Phải thu KH - {$invoice->code}"],
        ];

        // Doanh thu: hàng hóa → 5111, dịch vụ → 5113 (phân tách đơn giản dựa subtotal)
        if ($subtotal > 0) {
            $lines[] = ['account' => '5111', 'debit' => 0, 'credit' => $subtotal,
                        'description' => "Doanh thu - {$invoice->code}"];
        }
        if ($tax > 0) {
            $lines[] = ['account' => '33311', 'debit' => 0, 'credit' => $tax,
                        'description' => "Thuế GTGT đầu ra - {$invoice->code}"];
        }
        // Nếu không có VAT (tax=0), tổng debit=subtotal=total
        if ($tax == 0) {
            $lines[0]['debit'] = $subtotal;
        }

        $this->tryPost("Ghi nhận doanh thu {$invoice->code}", Carbon::parse($invoice->issue_date),
            $lines, 'invoice', $invoice->id);
    }

    private function postPaymentEntry(Payment $payment, Invoice $invoice): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) return;

        $method = $payment->method instanceof \App\Enums\PaymentMethod
            ? $payment->method->value
            : ($payment->method ?? 'cash');
        $cashAccount = $this->resolvePaymentAccount($method);

        $this->tryPost(
            "Thu tiền {$invoice->code}",
            Carbon::parse($payment->payment_date),
            [
                ['account' => $cashAccount, 'debit' => $amount, 'credit' => 0,
                 'description' => "Thu tiền - {$invoice->code}"],
                ['account' => '131', 'debit' => 0, 'credit' => $amount,
                 'description' => "Xóa công nợ KH - {$invoice->code}"],
            ],
            'payment', $payment->id
        );
    }

    private function resolvePaymentAccount(string $method): string
    {
        return match($method) {
            'bank_transfer', 'bank' => '112',
            default => '111',
        };
    }

    private function tryPost(string $description, Carbon $date, array $lines, string $refType, int $refId): void
    {
        try {
            $this->accounting->post($description, $date, $lines, $refType, $refId, true);
        } catch (\Exception $e) {
            // Không để lỗi kế toán block nghiệp vụ — log để xem lại
            \Log::warning("Auto-posting failed [{$description}]: " . $e->getMessage());
        }
    }
}
