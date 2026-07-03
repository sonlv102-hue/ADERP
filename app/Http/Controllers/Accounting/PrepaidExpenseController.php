<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\PrepaidExpense;
use App\Models\Supplier;
use App\Services\PrepaidExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PrepaidExpenseController extends Controller
{
    public function __construct(private PrepaidExpenseService $service) {}

    public function index(Request $request): Response
    {
        $query = PrepaidExpense::with('supplier')
            ->when($request->search, fn($q, $s) =>
                $q->where('code', 'ilike', "%{$s}%")
                  ->orWhere('description', 'ilike', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->orderByDesc('id');

        return Inertia::render('Accounting/PrepaidExpenses/Index', [
            'expenses' => $query->paginate(20)->through(fn($e) => [
                'id'                 => $e->id,
                'code'               => $e->code,
                'description'        => $e->description,
                'supplier_name'      => $e->supplier?->name,
                'account_code'       => $e->account_code,
                'total_amount'       => (float) $e->total_amount,
                'amortized_amount'   => (float) $e->amortized_amount,
                'remaining_amount'   => $e->remainingAmount(),
                'months'             => $e->months,
                'start_date'         => $e->start_date->format('Y-m-d'),
                'end_date'           => $e->endDate()->format('Y-m-d'),
                'status'             => $e->status->value,
                'status_label'       => $e->status->label(),
                'status_color'       => $e->status->color(),
                'allocation_status'  => $e->allocation_status,
                'is_opening_balance' => $e->is_opening_balance,
            ]),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/PrepaidExpenses/Form', [
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
            'creditOptions' => [
                ['code' => '3311', 'label' => '3311 — NCC trong nước'],
                ['code' => '3312', 'label' => '3312 — NCC nước ngoài'],
                ['code' => '1111', 'label' => '1111 — Tiền mặt VND'],
                ['code' => '1121', 'label' => '1121 — Tiền gửi VND'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'description'     => 'required|string|max:300',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'account_code'    => 'required|in:142,242',
            'expense_account' => 'required|string|max:10',
            'credit_account'  => 'required|string|max:10',
            'total_amount'    => 'required|integer|min:1',
            'start_date'      => 'required|date',
            'months'          => 'required|integer|min:1|max:120',
            'notes'           => 'nullable|string|max:500',
        ]);

        $expense = $this->service->create($data);

        return redirect()->route('accounting.prepaid-expenses.show', $expense)
            ->with('success', "Đã tạo chi phí trả trước {$expense->code}.");
    }

    public function show(PrepaidExpense $prepaidExpense): Response
    {
        $prepaidExpense->load(['supplier', 'allocations.journalEntry', 'creator', 'pausedByUser', 'resumedByUser']);

        $history = \Spatie\Activitylog\Models\Activity::forSubject($prepaidExpense)
            ->with('causer')->latest()->get()->map(fn ($log) => [
                'description' => $log->description,
                'causer_name' => $log->causer?->name ?? 'Hệ thống',
                'created_at'  => $log->created_at->format('d/m/Y H:i'),
                'properties'  => $log->properties->toArray(),
            ]);

        return Inertia::render('Accounting/PrepaidExpenses/Show', [
            'expense' => [
                'id'                      => $prepaidExpense->id,
                'code'                    => $prepaidExpense->code,
                'description'             => $prepaidExpense->description,
                'supplier_name'           => $prepaidExpense->supplier?->name,
                'account_code'            => $prepaidExpense->account_code,
                'expense_account'         => $prepaidExpense->expense_account,
                'total_amount'            => (float) $prepaidExpense->total_amount,
                'monthly_amount'          => (float) $prepaidExpense->monthly_amount,
                'amortized_amount'        => (float) $prepaidExpense->amortized_amount,
                'remaining_amount'        => $prepaidExpense->remainingAmount(),
                'months'                  => $prepaidExpense->months,
                'start_date'              => $prepaidExpense->start_date->format('Y-m-d'),
                'end_date'                => $prepaidExpense->endDate()->format('Y-m-d'),
                'status'                  => $prepaidExpense->status->value,
                'status_label'            => $prepaidExpense->status->label(),
                'status_color'            => $prepaidExpense->status->color(),
                'notes'                   => $prepaidExpense->notes,
                'creator'                 => $prepaidExpense->creator?->name,
                'is_opening_balance'      => $prepaidExpense->is_opening_balance,
                'opening_balance_period'  => $prepaidExpense->opening_balance_period,
                'opening_journal_entry_id'=> $prepaidExpense->opening_journal_entry_id,
                'allocation_status'       => $prepaidExpense->allocation_status,
                'pause_reason'            => $prepaidExpense->pause_reason,
                'pause_effective_period'  => $prepaidExpense->pause_effective_period,
                'paused_at'               => $prepaidExpense->paused_at?->format('d/m/Y H:i'),
                'paused_by_name'          => $prepaidExpense->pausedByUser?->name,
                'resumed_at'              => $prepaidExpense->resumed_at?->format('d/m/Y H:i'),
                'resumed_by_name'         => $prepaidExpense->resumedByUser?->name,
                'can_pause'               => $prepaidExpense->canPauseAllocation(),
                'can_resume'              => $prepaidExpense->canResumeAllocation(),
                'allocations'             => $prepaidExpense->allocations->map(fn($a) => [
                    'id'               => $a->id,
                    'period'           => $a->period,
                    'amount'           => (float) $a->amount,
                    'journal_entry_code' => $a->journalEntry?->code,
                    'journal_entry_id'   => $a->journal_entry_id,
                ])->all(),
            ],
            'history'       => $history,
            'currentPeriod' => now()->format('Y-m'),
        ]);
    }

    public function amortize(Request $request, PrepaidExpense $prepaidExpense): RedirectResponse
    {
        $data = $request->validate([
            'period' => 'required|regex:/^\d{4}-\d{2}$/',
        ]);

        try {
            $this->service->amortize($prepaidExpense, $data['period']);
            return back()->with('success', "Đã phân bổ kỳ {$data['period']}.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function runBatch(Request $request): RedirectResponse
    {
        $data    = $request->validate(['period' => 'required|regex:/^\d{4}-\d{2}$/']);
        $results = $this->service->runMonthlyAmortization($data['period']);

        return back()->with('success',
            "Phân bổ hàng loạt kỳ {$data['period']}: {$results['success']} thành công, {$results['skipped']} bỏ qua."
            . (count($results['errors']) ? ' Lỗi: ' . implode('; ', $results['errors']) : '')
        );
    }

    public function pause(PrepaidExpense $prepaidExpense, Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $this->service->pause($prepaidExpense, $data['reason']);
            return back()->with('success', "Đã tạm dừng phân bổ {$prepaidExpense->code}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function resume(PrepaidExpense $prepaidExpense): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->service->resume($prepaidExpense);
            return back()->with('success', "Đã tiếp tục phân bổ {$prepaidExpense->code}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // Đối soát GL — TK 142/242, cộng đại số theo dấu (không dùng abs)
    public function glReconcile(Request $request): Response
    {
        $this->authorize('accounting.view');

        $asOf = $request->input('as_of', now()->toDateString());

        $glBalance = function (string $account) use ($asOf): float {
            return (float) (DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.account_code', $account)
                ->where('journal_entries.status', 'posted')
                ->where('journal_entries.entry_date', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
                ->value('balance') ?? 0);
        };

        $expenses = PrepaidExpense::whereIn('status', ['active', 'fully_amortized'])->get();
        $accounts = $expenses->pluck('account_code')->unique()->values();

        $byAccount = $accounts->map(function ($code) use ($expenses, $glBalance) {
            $bookRemaining = $expenses->where('account_code', $code)->sum(fn ($e) => $e->remainingAmount());
            $gl            = $glBalance($code);
            return [
                'account'        => $code,
                'gl_balance'     => $gl,
                'book_remaining' => $bookRemaining,
                'diff'           => round($gl - $bookRemaining, 2),
            ];
        })->values();

        return Inertia::render('Accounting/PrepaidExpenses/GlReconcile', [
            'asOf'      => $asOf,
            'byAccount' => $byAccount,
            'expenses'  => $expenses->map(fn ($e) => [
                'code'             => $e->code,
                'description'      => $e->description,
                'account_code'     => $e->account_code,
                'total_amount'     => (float) $e->total_amount,
                'amortized_amount' => (float) $e->amortized_amount,
                'remaining_amount' => $e->remainingAmount(),
                'status'           => $e->status->value,
            ])->values(),
        ]);
    }
}
