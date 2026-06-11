<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\PrepaidExpense;
use App\Models\Supplier;
use App\Services\PrepaidExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'id'               => $e->id,
                'code'             => $e->code,
                'description'      => $e->description,
                'supplier_name'    => $e->supplier?->name,
                'account_code'     => $e->account_code,
                'total_amount'     => (float) $e->total_amount,
                'amortized_amount' => (float) $e->amortized_amount,
                'remaining_amount' => $e->remainingAmount(),
                'months'           => $e->months,
                'start_date'       => $e->start_date->format('Y-m-d'),
                'end_date'         => $e->endDate()->format('Y-m-d'),
                'status'           => $e->status->value,
                'status_label'     => $e->status->label(),
                'status_color'     => $e->status->color(),
            ]),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/PrepaidExpenses/Form', [
            'suppliers'      => Supplier::orderBy('name')->get(['id', 'name']),
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
                ['code' => '331',  'label' => '331 — Phải trả người bán'],
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
        $prepaidExpense->load(['supplier', 'allocations.journalEntry', 'creator']);

        return Inertia::render('Accounting/PrepaidExpenses/Show', [
            'expense' => [
                'id'               => $prepaidExpense->id,
                'code'             => $prepaidExpense->code,
                'description'      => $prepaidExpense->description,
                'supplier_name'    => $prepaidExpense->supplier?->name,
                'account_code'     => $prepaidExpense->account_code,
                'expense_account'  => $prepaidExpense->expense_account,
                'total_amount'     => (float) $prepaidExpense->total_amount,
                'monthly_amount'   => (float) $prepaidExpense->monthly_amount,
                'amortized_amount' => (float) $prepaidExpense->amortized_amount,
                'remaining_amount' => $prepaidExpense->remainingAmount(),
                'months'           => $prepaidExpense->months,
                'start_date'       => $prepaidExpense->start_date->format('Y-m-d'),
                'end_date'         => $prepaidExpense->endDate()->format('Y-m-d'),
                'status'           => $prepaidExpense->status->value,
                'status_label'     => $prepaidExpense->status->label(),
                'status_color'     => $prepaidExpense->status->color(),
                'notes'            => $prepaidExpense->notes,
                'creator'          => $prepaidExpense->creator?->name,
                'allocations'      => $prepaidExpense->allocations->map(fn($a) => [
                    'id'               => $a->id,
                    'period'           => $a->period,
                    'amount'           => (float) $a->amount,
                    'journal_entry_code' => $a->journalEntry?->code,
                    'journal_entry_id'   => $a->journal_entry_id,
                ])->all(),
            ],
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
}
