<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\PrepaidExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\PrepaidExpense;
use App\Models\Supplier;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PrepaidExpenseOpeningBalanceController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function create(): Response
    {
        $this->authorize('accounting.manage');

        return Inertia::render('Accounting/PrepaidExpenses/OpeningBalance/Form', [
            'suppliers'      => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accountOptions' => [
                ['code' => '142', 'label' => '142 — Chi phí trả trước ngắn hạn'],
                ['code' => '242', 'label' => '242 — Chi phí trả trước dài hạn'],
            ],
            'expenseOptions' => [
                ['code' => '6421', 'label' => '6421 — Chi phí bán hàng'],
                ['code' => '6422', 'label' => '6422 — Chi phí QLDN'],
                ['code' => '627',  'label' => '627 — Chi phí sản xuất chung'],
                ['code' => '635',  'label' => '635 — Chi phí tài chính'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'description'             => 'required|string|max:300',
            'supplier_id'             => 'nullable|exists:suppliers,id',
            'account_code'            => 'required|in:142,242',
            'expense_account'         => 'required|string|max:10',
            'total_amount'            => 'required|integer',
            'months'                  => 'required|integer|min:1|max:120',
            'periods_elapsed'         => 'required|integer|min:0|lt:months',
            'remaining_amount'        => 'required|integer|not_in:0',
            'opening_balance_period'  => 'required|regex:/^\d{4}-\d{2}$/',
            'notes'                   => 'nullable|string|max:500',
        ]);

        $remainingMonths = $data['months'] - $data['periods_elapsed'];
        $remaining       = (int) $data['remaining_amount'];
        $monthlyAmount   = $remaining >= 0
            ? (int) ceil($remaining / $remainingMonths)
            : -(int) ceil(abs($remaining) / $remainingMonths);

        // Lùi start_date lại đúng periods_elapsed tháng để endDate() (start_date + months - 1) phản ánh
        // đúng kỳ cuối thực tế = opening_balance_period + remainingMonths - 1.
        $scheduleStartDate = Carbon::parse($data['opening_balance_period'] . '-01')->subMonths((int) $data['periods_elapsed']);

        $expense = DB::transaction(function () use ($data, $remaining, $monthlyAmount, $scheduleStartDate) {
            $expense = PrepaidExpense::create([
                'code'                     => PrepaidExpense::generateCode(),
                'description'              => $data['description'],
                'supplier_id'              => $data['supplier_id'] ?? null,
                'account_code'             => $data['account_code'],
                'expense_account'          => $data['expense_account'],
                'total_amount'             => $data['total_amount'],
                'start_date'               => $scheduleStartDate,
                'months'                   => $data['months'],
                'monthly_amount'           => $monthlyAmount,
                'amortized_amount'         => $data['total_amount'] - $remaining,
                'status'                   => PrepaidExpenseStatus::Active,
                'allocation_status'        => 'active',
                'is_opening_balance'       => true,
                'opening_balance_period'   => $data['opening_balance_period'],
                'opening_balance_note'     => $data['notes'] ?? null,
                'opening_periods_elapsed'  => $data['periods_elapsed'],
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => auth()->id(),
            ]);

            $je = $this->createOpeningBalanceJournal($expense, $remaining);
            $expense->update(['opening_journal_entry_id' => $je->id]);

            return $expense;
        });

        return redirect()->route('accounting.prepaid-expenses.show', $expense)
            ->with('success', "Đã nhập số dư đầu kỳ chi phí trả trước {$expense->code}.");
    }

    public function destroy(PrepaidExpense $prepaidExpense): RedirectResponse
    {
        $this->authorize('accounting.manage');

        if (! $prepaidExpense->is_opening_balance) {
            return back()->with('error', 'Đây không phải bản ghi số dư đầu kỳ.');
        }

        if ($prepaidExpense->allocations()->exists()) {
            return back()->with('error', 'Đã có kỳ phân bổ ghi sổ trên chi phí này — không thể xóa.');
        }

        $prepaidExpense->loadMissing('openingJournalEntry');
        if ($prepaidExpense->openingJournalEntry && $prepaidExpense->openingJournalEntry->status === 'posted') {
            return back()->with('error', 'Bút toán số dư đầu kỳ đã ghi sổ — hãy hủy (void) bút toán đó trước khi xóa.');
        }

        $prepaidExpense->delete();

        return redirect()->route('accounting.prepaid-expenses.index')->with('success', 'Đã xóa số dư đầu kỳ chi phí trả trước.');
    }

    /**
     * Ghi nhận giá trị còn lại (không phải total_amount gốc), đối ứng 4111 — lưỡng tính theo dấu, giống ArApOpeningBalance.
     */
    private function createOpeningBalanceJournal(PrepaidExpense $expense, int $remaining): \App\Models\JournalEntry
    {
        $abs  = abs($remaining);
        $date = Carbon::createFromFormat('Y-m', $expense->opening_balance_period)->startOfMonth()->subDay();

        $lines = $remaining >= 0
            ? [
                ['account' => $expense->account_code, 'debit' => $abs, 'credit' => 0, 'description' => "Số dư đầu kỳ CPTT: {$expense->description}"],
                ['account' => '4111', 'debit' => 0, 'credit' => $abs, 'description' => "Số dư đầu kỳ CPTT {$expense->opening_balance_period}"],
            ]
            : [
                ['account' => '4111', 'debit' => $abs, 'credit' => 0, 'description' => "Số dư đầu kỳ CPTT {$expense->opening_balance_period}"],
                ['account' => $expense->account_code, 'debit' => 0, 'credit' => $abs, 'description' => "Số dư đầu kỳ CPTT: {$expense->description}"],
            ];

        return $this->accounting->post(
            description: "Số dư đầu kỳ chi phí trả trước: {$expense->code} - {$expense->description}",
            date: $date,
            lines: $lines,
            referenceType: 'prepaid_expense_opening_balance',
            referenceId: $expense->id,
            isAuto: false,
            journalSourceType: 'prepaid_expense_opening_balance',
            excludeFromPeriodMovement: true,
            fiscalPeriod: $expense->opening_balance_period,
        );
    }
}
