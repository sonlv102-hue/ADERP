<?php

namespace App\Services;

use App\Enums\PrepaidExpenseStatus;
use App\Models\PrepaidExpense;
use App\Models\PrepaidExpenseAllocation;
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
            $creditAccount = $data['credit_account'] ?? '331';
            try {
                $this->accounting->post(
                    description: 'Ghi nhận chi phí trả trước: ' . $expense->description,
                    date: Carbon::parse($data['start_date']),
                    lines: [
                        ['account' => $expense->account_code, 'debit' => (int) $data['total_amount'], 'credit' => 0],
                        ['account' => $creditAccount, 'debit' => 0, 'credit' => (int) $data['total_amount']],
                    ],
                    referenceType: PrepaidExpense::class,
                    referenceId: $expense->id,
                    isAuto: true,
                );
            } catch (\Exception $e) {
                \Log::warning("PrepaidExpense auto-posting failed: " . $e->getMessage());
            }

            return $expense;
        });
    }

    /**
     * Phân bổ 1 tháng cho một chi phí trả trước.
     * Dr expense_account / Cr 142/242
     */
    public function amortize(PrepaidExpense $expense, string $period): PrepaidExpenseAllocation
    {
        if ($expense->status !== PrepaidExpenseStatus::Active) {
            throw new \RuntimeException('Chỉ có thể phân bổ chi phí đang hoạt động.');
        }

        if ($expense->allocations()->where('period', $period)->exists()) {
            throw new \RuntimeException("Kỳ {$period} đã được phân bổ.");
        }

        // Tháng cuối: lấy số dư còn lại để tránh sai lệch làm tròn
        $allocatedCount    = $expense->allocatedMonths();
        $remainingMonths   = $expense->months - $allocatedCount;
        $amount = $remainingMonths === 1
            ? (int) $expense->remainingAmount()
            : (int) $expense->monthly_amount;

        return DB::transaction(function () use ($expense, $period, $amount) {
            $journalEntry = null;
            try {
                $date = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
                $journalEntry = $this->accounting->post(
                    description: "Phân bổ chi phí trả trước {$period}: " . $expense->description,
                    date: $date,
                    lines: [
                        ['account' => $expense->expense_account, 'debit' => $amount, 'credit' => 0],
                        ['account' => $expense->account_code,    'debit' => 0, 'credit' => $amount],
                    ],
                    referenceType: PrepaidExpense::class,
                    referenceId: $expense->id,
                    isAuto: true,
                );
            } catch (\Exception $e) {
                \Log::warning("PrepaidExpense amortization posting failed: " . $e->getMessage());
            }

            $allocation = PrepaidExpenseAllocation::create([
                'prepaid_expense_id' => $expense->id,
                'period'             => $period,
                'amount'             => $amount,
                'journal_entry_id'   => $journalEntry?->id,
            ]);

            $newAmortized = (float) $expense->amortized_amount + $amount;
            $newStatus    = $newAmortized >= (float) $expense->total_amount
                ? PrepaidExpenseStatus::FullyAmortized
                : PrepaidExpenseStatus::Active;

            $expense->update([
                'amortized_amount' => $newAmortized,
                'status'           => $newStatus,
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
}
