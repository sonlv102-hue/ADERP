<?php

namespace App\Services;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\CashVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashVoucherService
{
    public function __construct(private AccountingService $accounting) {}

    public function confirm(CashVoucher $voucher): void
    {
        if ($voucher->status !== CashVoucherStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        if ($voucher->amount <= 0) {
            throw new RuntimeException('Số tiền phải lớn hơn 0.');
        }

        $voucher->update(['status' => CashVoucherStatus::Confirmed]);

        // Hạch toán phiếu thu/chi
        $this->postVoucherJournal($voucher);
    }

    public function cancel(CashVoucher $voucher): void
    {
        if ($voucher->status === CashVoucherStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        DB::transaction(function () use ($voucher) {
            $this->accounting->reverseOrDelete('cash_voucher', $voucher->id, "Hủy {$voucher->type->label()} {$voucher->code}");
            $voucher->update(['status' => CashVoucherStatus::Cancelled]);
        });
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function postVoucherJournal(CashVoucher $voucher): void
    {
        $amount  = (float) $voucher->amount;
        $account = $this->resolveFundAccount($voucher);
        $date    = Carbon::parse($voucher->voucher_date);

        if ($voucher->type === CashVoucherType::Receipt) {
            // Phiếu thu: Dr 111/112 / Cr 131 hoặc 511
            $counterAccount = $this->resolveCounterAccount($voucher, 'receipt');
            $lines = [
                ['account' => $account, 'debit' => (int) $amount, 'credit' => 0,
                 'description' => "Thu: {$voucher->description}"],
                ['account' => $counterAccount, 'debit' => 0, 'credit' => (int) $amount,
                 'description' => "Thu: {$voucher->description}"],
            ];
        } else {
            // Phiếu chi: Dr 642/641/... / Cr 111/112
            $counterAccount = $this->resolveCounterAccount($voucher, 'payment');
            $lines = [
                ['account' => $counterAccount, 'debit' => (int) $amount, 'credit' => 0,
                 'description' => "Chi: {$voucher->description}"],
                ['account' => $account, 'debit' => 0, 'credit' => (int) $amount,
                 'description' => "Chi: {$voucher->description}"],
            ];
        }

        $this->accounting->tryPost(
            "{$voucher->type->label()} {$voucher->code}",
            $date, $lines, 'cash_voucher', $voucher->id, 'confirm'
        );
    }

    /** Tài khoản quỹ/ngân hàng của phiếu (1111/1121 tùy loại Fund) */
    private function resolveFundAccount(CashVoucher $voucher): string
    {
        $voucher->loadMissing('fund');
        if ($voucher->fund && $voucher->fund->type === 'bank') {
            return '1121'; // Tiền gửi VND — chi tiết của 112
        }
        return '1111'; // Tiền mặt VND — chi tiết của 111
    }

    /** Tài khoản đối ứng mặc định */
    private function resolveCounterAccount(CashVoucher $voucher, string $direction): string
    {
        // Thu từ KH (gắn HD bán hàng) → lấy receivable_account từ customer qua invoice
        if ($direction === 'receipt' && $voucher->reference_type === 'invoice') {
            $invoice = \App\Models\Invoice::find($voucher->reference_id);
            if ($invoice?->customer_id) {
                $invoice->loadMissing('customer');
                return $invoice->customer->getReceivableAccount();
            }
            return '1311'; // fallback nếu không tìm được invoice/customer
        }
        // Liên quan NCC: có supplier_id → lấy payable_account_code từ NCC
        if ($voucher->supplier_id) {
            $voucher->loadMissing('supplier');
            return $voucher->supplier->getPayableAccount();
        }
        // Gắn HD mua hàng (không có supplier_id trực tiếp trên voucher) → fallback 3311
        // Trường hợp này hiếm — phiếu chi nên luôn gắn supplier_id khi thanh toán NCC
        if ($voucher->reference_type === 'purchase_invoice') {
            return '3311';
        }
        // Mặc định: thu → 1311 (phải thu KH trong nước), chi → 6422 (Chi phí QLDN)
        return $direction === 'receipt' ? '1311' : '6422';
    }
}
