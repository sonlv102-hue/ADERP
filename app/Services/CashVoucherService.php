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
        [$partnerType, $partnerId] = $this->resolvePartnerInfo($voucher);

        if ($voucher->type === CashVoucherType::Receipt) {
            // Phiếu thu: Dr 111/112 / Cr 131 hoặc 511
            $counterAccount = $this->resolveCounterAccount($voucher, 'receipt');
            $lines = [
                ['account' => $account, 'debit' => (int) $amount, 'credit' => 0,
                 'description' => "Thu: {$voucher->description}"],
                ['account' => $counterAccount, 'debit' => 0, 'credit' => (int) $amount,
                 'description' => "Thu: {$voucher->description}",
                 'partner_type' => $partnerType, 'partner_id' => $partnerId],
            ];
        } else {
            // Phiếu chi: Dr 642/641/141/... / Cr 111/112
            $counterAccount = $this->resolveCounterAccount($voucher, 'payment');
            $lines = [
                ['account' => $counterAccount, 'debit' => (int) $amount, 'credit' => 0,
                 'description' => "Chi: {$voucher->description}",
                 'partner_type' => $partnerType, 'partner_id' => $partnerId],
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

    /** Tài khoản đối ứng theo loại đối tác */
    private function resolveCounterAccount(CashVoucher $voucher, string $direction): string
    {
        switch ($voucher->partner_type) {
            case 'customer':
                if ($voucher->customer_id) {
                    $voucher->loadMissing('customer');
                    return $voucher->customer->getReceivableAccount();
                }
                return '1311';

            case 'supplier':
                if ($voucher->supplier_id) {
                    $voucher->loadMissing('supplier');
                    return $voucher->supplier->getPayableAccount();
                }
                return '3311';

            case 'employee':
                return '141'; // Tạm ứng nhân viên

            default:
                // Legacy: thu từ KH qua HD bán hàng
                if ($direction === 'receipt' && $voucher->reference_type === 'invoice') {
                    $invoice = \App\Models\Invoice::find($voucher->reference_id);
                    if ($invoice?->customer_id) {
                        $invoice->loadMissing('customer');
                        return $invoice->customer->getReceivableAccount();
                    }
                    return '1311';
                }
                // Legacy: supplier_id trực tiếp
                if ($voucher->supplier_id) {
                    $voucher->loadMissing('supplier');
                    return $voucher->supplier->getPayableAccount();
                }
                if ($voucher->reference_type === 'purchase_invoice') {
                    return '3311';
                }
                return $direction === 'receipt' ? '1311' : '6422';
        }
    }

    /** Thông tin đối tác để gắn vào dòng bút toán AR/AP/141 */
    private function resolvePartnerInfo(CashVoucher $voucher): array
    {
        return match ($voucher->partner_type) {
            'customer' => ['customer', $voucher->customer_id],
            'supplier' => ['supplier', $voucher->supplier_id],
            'employee' => ['employee', $voucher->employee_id],
            default    => $voucher->supplier_id
                ? ['supplier', $voucher->supplier_id]
                : [null, null],
        };
    }
}
