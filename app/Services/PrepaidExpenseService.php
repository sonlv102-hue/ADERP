<?php

namespace App\Services;

use App\Enums\PrepaidExpenseStatus;
use App\Models\PrepaidExpense;
use App\Models\PrepaidExpenseAllocation;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PrepaidExpenseService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Tạo chi phí trả trước và hạch toán bút toán ghi nhận ban đầu.
     * Dr 142/242 / Cr 331 (nếu mua chịu) hoặc Cr 111/112 (nếu trả ngay)
     */
    public function create(array $data): PrepaidExpense
    {
        $monthlyAmount = (int) ceil((int) $data['total_amount'] / (int) $data['months']);

        return DB::transaction(function () use ($data, $monthlyAmount) {
            $expense = PrepaidExpense::create([
                'code'            => PrepaidExpense::generateCode(),
                'description'     => $data['description'],
                'supplier_id'     => $data['supplier_id'] ?? null,
                'account_code'    => $data['account_code'],
                'expense_account' => $data['expense_account'],
                'total_amount'    => $data['total_amount'],
                'start_date'      => $data['start_date'],
                'months'          => $data['months'],
                'monthly_amount'  => $monthlyAmount,
                'amortized_amount'=> 0,
                'status'          => PrepaidExpenseStatus::Active,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => auth()->id(),
            ]);

            // Bút toán ghi nhận: Dr 142/242 / Cr nguồn tiền
            $creditAccount = $data['credit_account'] ?? AccountingSettings::get('default_ap_account', '3311');
            $this->accounting->tryPost(
                description: 'Ghi nhận chi phí trả trước: ' . $expense->description,
                date: Carbon::parse($data['start_date']),
                lines: [
                    ['account' => $expense->account_code, 'debit' => (int) $data['total_amount'], 'credit' => 0],
                    ['account' => $creditAccount, 'debit' => 0, 'credit' => (int) $data['total_amount']],
                ],
                sourceType: 'prepaid_expense',
                sourceId: $expense->id,
                postingType: 'recognition',
            );

            return $expense;
        });
    }

    /**
     * Phân bổ 1 tháng cho một chi phí trả trước.
     * Dr expense_account / Cr 142/242 (số dương). Đảo chiều nếu amount âm (điều chỉnh đầu kỳ).
     */
    public function amortize(PrepaidExpense $expense, string $period): PrepaidExpenseAllocation
    {
        if ($expense->status !== PrepaidExpenseStatus::Active) {
            throw new \RuntimeException('Chỉ có thể phân bổ chi phí đang hoạt động.');
        }

        if ($expense->isPaused() && (! $expense->pause_effective_period || $period >= $expense->pause_effective_period)) {
            throw new \RuntimeException("Chi phí đang tạm dừng phân bổ từ kỳ {$expense->pause_effective_period}.");
        }

        if ($expense->allocations()->where('period', $period)->exists()) {
            throw new \RuntimeException("Kỳ {$period} đã được phân bổ.");
        }

        // Tháng cuối: lấy số dư còn lại để tránh sai lệch làm tròn. Trừ cả số kỳ đã qua trước khi nhập số dư đầu kỳ.
        $allocatedCount    = $expense->allocatedMonths() + $expense->opening_periods_elapsed;
        $remainingMonths   = $expense->months - $allocatedCount;
        $amount = $remainingMonths === 1
            ? (int) $expense->remainingAmount()
            : (int) $expense->monthly_amount;

        return DB::transaction(function () use ($expense, $period, $amount, $remainingMonths) {
            $allocation = PrepaidExpenseAllocation::create([
                'prepaid_expense_id' => $expense->id,
                'period'             => $period,
                'amount'             => $amount,
                'journal_entry_id'   => null,
            ]);

            $date  = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            $lines = $amount >= 0
                ? [
                    ['account' => $expense->expense_account, 'debit' => $amount, 'credit' => 0],
                    ['account' => $expense->account_code,    'debit' => 0, 'credit' => $amount],
                ]
                : [
                    ['account' => $expense->account_code,    'debit' => abs($amount), 'credit' => 0],
                    ['account' => $expense->expense_account, 'debit' => 0, 'credit' => abs($amount)],
                ];

            $je = $this->accounting->tryPost(
                description: "Phân bổ chi phí trả trước {$period}: " . $expense->description,
                date: $date,
                lines: $lines,
                sourceType: 'prepaid_expense_allocation',
                sourceId: $allocation->id,
                postingType: 'amortization',
            );

            if ($je) {
                $allocation->update(['journal_entry_id' => $je->id]);
            }

            $newAmortized = (float) $expense->amortized_amount + $amount;
            // Kỳ cuối (remainingMonths===1) luôn nhận đúng phần dư còn lại → hết kỳ là hoàn thành,
            // bất kể remaining ban đầu dương hay âm (không dùng >= vì sai với trường hợp âm).
            $newStatus = $remainingMonths <= 1
                ? PrepaidExpenseStatus::FullyAmortized
                : PrepaidExpenseStatus::Active;

            $expense->update([
                'amortized_amount'  => $newAmortized,
                'status'            => $newStatus,
                'allocation_status' => $newStatus === PrepaidExpenseStatus::FullyAmortized ? 'completed' : $expense->allocation_status,
            ]);

            return $allocation;
        });
    }

    /**
     * Chạy phân bổ hàng loạt cho tất cả chi phí đang active.
     * Gọi vào đầu mỗi tháng hoặc cuối kỳ.
     */
    public function runMonthlyAmortization(string $period): array
    {
        $expenses = PrepaidExpense::where('status', PrepaidExpenseStatus::Active)->get();
        $results  = ['success' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($expenses as $expense) {
            if ($expense->isPaused() && (! $expense->pause_effective_period || $period >= $expense->pause_effective_period)) {
                $results['skipped']++;
                continue;
            }

            // Kiểm tra kỳ $period có nằm trong phạm vi phân bổ không
            $periodDate = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
            $endDate    = $expense->endDate();
            if ($periodDate->gt($endDate)) {
                $results['skipped']++;
                continue;
            }

            if ($expense->allocations()->where('period', $period)->exists()) {
                $results['skipped']++;
                continue;
            }

            try {
                $this->amortize($expense, $period);
                $results['success']++;
            } catch (\Exception $e) {
                $results['errors'][] = "[{$expense->code}] " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Tạm dừng / Tiếp tục phân bổ — không đụng amortized_amount, allocations đã tồn tại.
     */
    public function pause(PrepaidExpense $expense, ?string $reason): void
    {
        if (! $expense->canPauseAllocation()) {
            throw new \RuntimeException('Chi phí này không ở trạng thái có thể tạm dừng phân bổ.');
        }

        $currentPeriod = now()->format('Y-m');
        $hasPostedCurrentPeriod = $expense->allocations()->where('period', $currentPeriod)->exists();

        $effective = $hasPostedCurrentPeriod
            ? now()->addMonth()->format('Y-m')
            : $currentPeriod;

        $expense->update([
            'allocation_status'      => 'paused',
            'paused_at'              => now(),
            'paused_by'              => auth()->id(),
            'pause_effective_period' => $effective,
            'pause_reason'           => $reason,
        ]);
    }

    public function resume(PrepaidExpense $expense): array
    {
        if (! $expense->canResumeAllocation()) {
            throw new \RuntimeException('Chi phí này không ở trạng thái tạm dừng.');
        }

        $expense->update([
            'allocation_status' => 'active',
            'resumed_at'        => now(),
            'resumed_by'        => auth()->id(),
        ]);

        return ['remaining' => $expense->remainingAmount(), 'monthly_amount' => (float) $expense->monthly_amount];
    }
}
