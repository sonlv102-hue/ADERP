<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\PersonalExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\PersonalExpenseReport;
use App\Models\Shareholder;
use App\Services\PersonalExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PersonalExpenseController extends Controller
{
    public function __construct(private PersonalExpenseService $service) {}

    public function index(Request $request): Response
    {
        $query = PersonalExpenseReport::with('employee', 'shareholder', 'creator')
            ->orderByDesc('id');

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }
        if ($t = $request->input('person_type')) {
            $query->where('person_type', $t);
        }

        $reports = $query->paginate(25)->through(fn (PersonalExpenseReport $r) => [
            'id'           => $r->id,
            'report_no'    => $r->report_no,
            'expense_date' => $r->expense_date->format('d/m/Y'),
            'person_name'  => $r->personName(),
            'person_type'  => $r->person_type,
            'description'  => $r->description,
            'total_amount' => (float) $r->total_amount,
            'status'       => $r->status->value,
            'status_label' => $r->status->label(),
            'status_color' => $r->status->color(),
        ]);

        return Inertia::render('Accounting/PersonalExpenses/Index', [
            'reports'  => $reports,
            'filters'  => $request->only(['status', 'person_type']),
            'statuses' => collect(PersonalExpenseStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/PersonalExpenses/Form', [
            'report'       => null,
            'employees'    => $this->activeEmployees(),
            'shareholders' => $this->activeShareholders(),
            'expenseAccounts' => $this->expenseAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_type'    => 'required|in:employee,shareholder,other',
            'employee_id'    => 'nullable|exists:employees,id',
            'shareholder_id' => 'nullable|exists:shareholders,id',
            'person_name'    => 'nullable|string|max:100',
            'expense_date'   => 'required|date',
            'description'    => 'required|string|max:255',
            'lines'          => 'required|array|min:1',
            'lines.*.expense_account' => 'required|string|exists:account_codes,code',
            'lines.*.description'     => 'required|string|max:255',
            'lines.*.amount'          => 'required|numeric|min:1',
            'lines.*.vat_rate'        => 'required|numeric|in:0,5,8,10',
        ]);

        try {
            $report = $this->service->create($data);
            return redirect()->route('accounting.personal-expenses.show', $report)
                ->with('success', "Phiếu chi hộ {$report->report_no} đã được tạo.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(PersonalExpenseReport $personalExpense): Response
    {
        $personalExpense->load('employee', 'shareholder', 'creator', 'lines',
            'journalEntry', 'reimburseJournalEntry', 'reimbursedFund');

        return Inertia::render('Accounting/PersonalExpenses/Show', [
            'report' => $this->dto($personalExpense),
            'funds'  => $this->activeFunds(),
        ]);
    }

    public function post(PersonalExpenseReport $personalExpense): RedirectResponse
    {
        try {
            $this->service->post($personalExpense);
            return back()->with('success', "Phiếu {$personalExpense->report_no} đã được ghi sổ.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reimburse(Request $request, PersonalExpenseReport $personalExpense): RedirectResponse
    {
        $data = $request->validate([
            'fund_id'     => 'required|exists:funds,id',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $this->service->reimburse($personalExpense, $data);
            return back()->with('success', "Phiếu {$personalExpense->report_no} đã được hoàn tiền.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function dto(PersonalExpenseReport $r): array
    {
        return [
            'id'              => $r->id,
            'report_no'       => $r->report_no,
            'person_type'     => $r->person_type,
            'person_name'     => $r->personName(),
            'employee_id'     => $r->employee_id,
            'employee'        => $r->employee ? ['id' => $r->employee->id, 'name' => $r->employee->name] : null,
            'shareholder_id'  => $r->shareholder_id,
            'shareholder'     => $r->shareholder ? ['id' => $r->shareholder->id, 'name' => $r->shareholder->name] : null,
            'expense_date'    => $r->expense_date->format('Y-m-d'),
            'expense_date_f'  => $r->expense_date->format('d/m/Y'),
            'description'     => $r->description,
            'total_amount'    => (float) $r->total_amount,
            'vat_amount'      => (float) $r->vat_amount,
            'status'          => $r->status->value,
            'status_label'    => $r->status->label(),
            'status_color'    => $r->status->color(),
            'journal_entry'   => $r->journalEntry ? ['id' => $r->journalEntry->id, 'code' => $r->journalEntry->code] : null,
            'reimburse_je'    => $r->reimburseJournalEntry ? ['id' => $r->reimburseJournalEntry->id, 'code' => $r->reimburseJournalEntry->code] : null,
            'reimbursed_fund' => $r->reimbursedFund?->name,
            'reimbursed_at'   => $r->reimbursed_at?->format('d/m/Y H:i'),
            'creator'         => $r->creator?->name,
            'created_at'      => $r->created_at->format('d/m/Y H:i'),
            'lines'           => $r->lines->map(fn ($l) => [
                'id'              => $l->id,
                'expense_account' => $l->expense_account,
                'description'     => $l->description,
                'amount'          => (float) $l->amount,
                'vat_rate'        => (float) $l->vat_rate,
                'vat_amount'      => (float) $l->vat_amount,
                'net_amount'      => (float) $l->net_amount,
            ])->toArray(),
        ];
    }

    private function activeEmployees(): array
    {
        return Employee::where('status', 'active')->orderBy('name')
            ->get()->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'code' => $e->code])->toArray();
    }

    private function activeShareholders(): array
    {
        return Shareholder::where('is_active', true)->orderBy('name')
            ->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code])->toArray();
    }

    private function activeFunds(): array
    {
        return Fund::where('is_active', true)->orderBy('type')->orderBy('name')
            ->get()->map(fn ($f) => [
                'id'   => $f->id,
                'name' => $f->name,
                'type' => $f->type,
            ])->toArray();
    }

    private function expenseAccounts(): array
    {
        return AccountCode::where('is_detail', true)
            ->where('is_active', true)
            ->whereIn('type', ['expense', 'asset'])
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name])
            ->toArray();
    }
}
