<?php

namespace App\Services;

use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\CashVoucher;
use Carbon\Carbon;
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

        $voucher->update(['status' => CashVoucherStatus::Cancelled]);
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

        try {
            $this->accounting->post(
                "{$voucher->type->label()} {$voucher->code}",
                $date, $lines, 'cash_voucher', $voucher->id, true
            );
        } catch (\Exception $e) {
            \Log::warning("Auto-posting failed [CashVoucher {$voucher->code}]: " . $e->getMessage());
        }
    }

    /** Tài khoản quỹ/ngân hàng của phiếu (111/112 tùy loại Fund) */
    private function resolveFundAccount(CashVoucher $voucher): string
    {
        $voucher->loadMissing('fund');
        if ($voucher->fund && $voucher->fund->type === 'bank') {
            return '112';
        }
        return '111';
    }

    /** Tài khoản đối ứng mặc định */
    private function resolveCounterAccount(CashVoucher $voucher, string $direction): string
    {
        // Nếu reference là Invoice → 131 (thu từ KH)
        if ($direction === 'receipt' && $voucher->reference_type === 'invoice') {
            return '131';
        }
        // Nếu reference là PurchaseInvoice → 331 (trả NCC)
        if ($direction === 'payment' && $voucher->reference_type === 'purchase_invoice') {
            return '331';
        }
        // Mặc định: thu → 131, chi → 642 (Chi phí QLDN)
        return $direction === 'receipt' ? '131' : '642';
    }
}
