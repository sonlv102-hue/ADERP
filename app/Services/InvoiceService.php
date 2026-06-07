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
            $this->accounting->reverseOrDelete('invoice', $invoice->id, "Hủy hóa đơn {$invoice->code}");
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

            // Hạch toán thanh toán: Dr 111/112 / Cr 131
            $this->postPaymentEntry($payment, $invoice);

            return $payment;
        });

        return $payment;
    }

    public function removePayment(Invoice $invoice, Payment $payment): void
    {
        DB::transaction(function () use ($invoice, $payment) {
            $this->accounting->reverseOrDelete('payment', $payment->id, "Thu tiền {$invoice->code}");
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
        // Ngăn hạch toán trùng — kiểm tra journal entry đã tồn tại (kể cả draft chờ duyệt)
        $alreadyExists = JournalEntry::where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->exists();
        if ($alreadyExists) return;

        $subtotal = (float) $invoice->subtotal;
        $tax      = (float) $invoice->tax_amount;

        $creditSubtotal = (int) round($subtotal);
        $creditTax      = (int) round($tax);
        $totalCredit    = $creditSubtotal + $creditTax;

        if ($totalCredit <= 0) return;

        // Dr 131 = tổng Có đã round — đảm bảo bút toán cân bằng
        $lines = [
            ['account' => '131', 'debit' => $totalCredit, 'credit' => 0,
             'description' => "Phải thu KH - {$invoice->code}"],
        ];

        // Doanh thu: phân tách theo revenue_account_code từ order_items
        if ($creditSubtotal > 0) {
            foreach ($this->buildRevenueLines($invoice, $creditSubtotal) as $revLine) {
                $lines[] = $revLine;
            }
        }
        if ($creditTax > 0) {
            $lines[] = ['account' => '33311', 'debit' => 0, 'credit' => $creditTax,
                        'description' => "Thuế GTGT đầu ra - {$invoice->code}"];
        }

        $this->accounting->tryPost("Ghi nhận doanh thu {$invoice->code}", Carbon::parse($invoice->issue_date),
            $lines, 'invoice', $invoice->id, 'revenue');
    }

    private function postPaymentEntry(Payment $payment, Invoice $invoice): void
    {
        $amount = (float) $payment->amount;
        if ($amount <= 0) return;

        $method = $payment->method instanceof \App\Enums\PaymentMethod
            ? $payment->method->value
            : ($payment->method ?? 'cash');
        $cashAccount = $this->resolvePaymentAccount($method);

        $this->accounting->tryPost(
            "Thu tiền {$invoice->code}",
            Carbon::parse($payment->payment_date),
            [
                ['account' => $cashAccount, 'debit' => $amount, 'credit' => 0,
                 'description' => "Thu tiền - {$invoice->code}"],
                ['account' => '131', 'debit' => 0, 'credit' => $amount,
                 'description' => "Xóa công nợ KH - {$invoice->code}"],
            ],
            'payment', $payment->id, 'collection'
        );
    }

    // Phân tách creditSubtotal theo tỷ lệ revenue_account_code từ order_items.
    // Nếu invoice không có order_id → dùng invoice.revenue_account_code (nếu có) hoặc log warning + fallback 5111.
    // Nếu order_item có revenue_account_code = null → log warning + gộp vào 5111.
    private function buildRevenueLines(Invoice $invoice, int $creditSubtotal): array
    {
        if (!$invoice->order_id) {
            $account = $invoice->revenue_account_code;
            if (!$account) {
                \Log::warning("Invoice {$invoice->code} (standalone): thiếu revenue_account_code. "
                    . 'Fallback 5111. Vào form hóa đơn để chọn tài khoản doanh thu phù hợp.');
                $account = '5111';
            }
            return [['account' => $account, 'debit' => 0, 'credit' => $creditSubtotal,
                     'description' => "Doanh thu ({$account}) - {$invoice->code}"]];
        }

        $groups = DB::table('order_items')
            ->where('order_id', $invoice->order_id)
            ->selectRaw('COALESCE(revenue_account_code, \'5111\') as account_code,
                         SUM(quantity * unit_price) as group_total')
            ->groupBy('account_code')
            ->orderByDesc('group_total')
            ->get();

        if ($groups->isEmpty()) {
            return [['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
                     'description' => "Doanh thu - {$invoice->code}"]];
        }

        // Log cảnh báo nếu có dòng revenue_account_code = null (item_type chưa được cấu hình)
        $hasNull = DB::table('order_items')
            ->where('order_id', $invoice->order_id)
            ->whereNull('revenue_account_code')
            ->exists();
        if ($hasNull) {
            \Log::warning("Invoice {$invoice->code}: có order_item thiếu revenue_account_code. "
                . 'Đã fallback về 5111. Cần kế toán cấu hình products.item_type cho các sản phẩm này.');
        }

        $orderTotal = $groups->sum('group_total');
        if ($orderTotal <= 0) {
            return [['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
                     'description' => "Doanh thu - {$invoice->code}"]];
        }

        $lines     = [];
        $allocated = 0;
        $lastKey   = $groups->keys()->last();

        foreach ($groups as $key => $group) {
            if ($key === $lastKey) {
                $amount = $creditSubtotal - $allocated; // phần dư để tránh sai số làm lệch bút toán
            } else {
                $amount = (int) round($creditSubtotal * ($group->group_total / $orderTotal));
            }

            if ($amount <= 0) continue;

            $lines[]    = ['account' => $group->account_code, 'debit' => 0, 'credit' => $amount,
                           'description' => "Doanh thu ({$group->account_code}) - {$invoice->code}"];
            $allocated += $amount;
        }

        return $lines ?: [['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
                            'description' => "Doanh thu - {$invoice->code}"]];
    }

    private function resolvePaymentAccount(string $method): string
    {
        return match($method) {
            'bank_transfer', 'bank' => '112',
            default => '111',
        };
    }

}
