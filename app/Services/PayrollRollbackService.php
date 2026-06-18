<?php

namespace App\Services;

use App\Enums\CashVoucherStatus;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingJob;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PeriodCloseBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollRollbackService
{
    public function __construct(
        private CashVoucherService $cashVoucher,
        private AccountingService  $accounting,
    ) {}

    /**
     * Trả về dữ liệu xem trước (dry-run) trước khi hủy thanh toán.
     */
    public function preview(Payroll $payroll): array
    {
        $paidItems = $payroll->items()
            ->where('status', PayrollItemStatus::Paid)
            ->with(['employee', 'cashVoucher'])
            ->get();

        $vouchers = $paidItems->map(fn ($item) => [
            'employee_name'  => $item->employee?->name ?? '—',
            'amount'         => max(0, (float) $item->net_salary
                                    + (float) ($item->adjustment_amount ?? 0)
                                    - (float) ($item->advance ?? 0)),
            'voucher_code'   => $item->cashVoucher?->code,
            'voucher_status' => $item->cashVoucher?->status?->value,
        ])->values()->all();

        $accrualJe = JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first(['id', 'code', 'status']);

        $periodCloseBatch = PeriodCloseBatch::where('fiscal_period', '>=', $payroll->period)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('fiscal_period')
            ->first(['fiscal_period']);

        $now = now();
        $currentPeriod = AccountingPeriod::where('year', $now->year)
            ->where('month', $now->month)
            ->first();
        $currentPeriodOpen = !$currentPeriod || $currentPeriod->status === 'open';

        $totalAmount = $paidItems->sum(
            fn ($i) => max(0, (float) $i->net_salary
                            + (float) ($i->adjustment_amount ?? 0)
                            - (float) ($i->advance ?? 0))
        );

        return [
            'payroll_code'         => $payroll->code,
            'period'               => $payroll->period,
            'paid_count'           => $paidItems->count(),
            'total_amount'         => $totalAmount,
            'vouchers'             => $vouchers,
            'has_accrual_je'       => (bool) $accrualJe,
            'accrual_je_code'      => $accrualJe?->code,
            'accrual_je_status'    => $accrualJe?->status,
            'current_period_open'  => $currentPeriodOpen,
            'period_close_warning' => $periodCloseBatch
                ? "Đã có kết chuyển cuối kỳ {$periodCloseBatch->fiscal_period}. Kiểm tra lại báo cáo tài chính sau khi rollback."
                : null,
            'can_rollback'         => $paidItems->count() > 0,
        ];
    }

    /**
     * Thực hiện rollback thanh toán lương.
     *
     * $scope:
     *   'payment_only'         — Hủy phiếu chi, hoàn quỹ. Bảng lương → confirmed.
     *   'payment_and_accrual'  — payment_only + đảo bút toán ghi nhận lương. Bảng lương → draft.
     */
    public function rollback(Payroll $payroll, string $scope, string $reason): void
    {
        if (!in_array($scope, ['payment_only', 'payment_and_accrual'], true)) {
            throw new RuntimeException('Phạm vi rollback không hợp lệ.');
        }

        $paidItems = $payroll->items()
            ->where('status', PayrollItemStatus::Paid)
            ->with('cashVoucher')
            ->get();

        if ($paidItems->isEmpty()) {
            throw new RuntimeException('Bảng lương không có dòng nào đã thanh toán.');
        }

        DB::transaction(function () use ($payroll, $paidItems, $scope, $reason) {
            // Bước 1: Hủy từng phiếu chi + reset dòng lương về pending
            foreach ($paidItems as $item) {
                if ($item->cashVoucher && $item->cashVoucher->status !== CashVoucherStatus::Cancelled) {
                    $this->cashVoucher->cancel($item->cashVoucher);
                }

                $item->update([
                    'status'                  => PayrollItemStatus::Pending,
                    'paid_at'                 => null,
                    'cash_voucher_id'         => null,
                    'salary_journal_entry_id' => null,
                ]);
            }

            // Bước 2: Đưa bảng lương về confirmed (nếu đang paid)
            if ($payroll->status === PayrollStatus::Paid) {
                $payroll->update(['status' => PayrollStatus::Confirmed]);
            }

            // Bước 3 (chỉ payment_and_accrual): Đảo bút toán ghi nhận lương → draft
            if ($scope === 'payment_and_accrual') {
                $this->accounting->reverseOrDelete(
                    'payroll',
                    $payroll->id,
                    "Hủy bút toán lương {$payroll->code}: {$reason}"
                );

                AccountingPostingJob::where('source_type', 'payroll')
                    ->where('source_id', $payroll->id)
                    ->where('posting_type', 'salary')
                    ->delete();

                $payroll->update(['status' => PayrollStatus::Draft]);
            }
        });

        activity()
            ->causedBy(auth()->user())
            ->performedOn($payroll)
            ->withProperties([
                'scope'       => $scope,
                'reason'      => $reason,
                'items_count' => $paidItems->count(),
            ])
            ->log('payroll_rollback');
    }
}
