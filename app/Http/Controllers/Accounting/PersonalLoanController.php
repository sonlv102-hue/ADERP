<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\PersonalLoanStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\PersonalLoan;
use App\Models\Shareholder;
use App\Services\PersonalLoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PersonalLoanController extends Controller
{
    public function __construct(private PersonalLoanService $service) {}

    public function index(Request $request): Response
    {
        $query = PersonalLoan::with('employee', 'shareholder', 'creator')
            ->orderByDesc('loan_date')->orderByDesc('id');

        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }
        if ($t = $request->input('lender_type')) {
            $query->where('lender_type', $t);
        }

        $loans = $query->paginate(25)->through(fn (PersonalLoan $l) => [
            'id'              => $l->id,
            'loan_no'         => $l->loan_no,
            'loan_date'       => $l->loan_date->format('d/m/Y'),
            'lender_name'     => $l->lenderName(),
            'lender_type'     => $l->lender_type,
            'amount'          => (float) $l->amount,
            'repaid_amount'   => (float) $l->repaid_amount,
            'remaining'       => $l->remainingAmount(),
            'due_date'        => $l->due_date?->format('d/m/Y'),
            'status'          => $l->status->value,
            'status_label'    => $l->status->label(),
            'status_color'    => $l->status->color(),
        ]);

        return Inertia::render('Accounting/PersonalLoans/Index', [
            'loans'        => $loans,
            'filters'      => $request->only(['status', 'lender_type']),
            'statuses'     => collect(PersonalLoanStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/PersonalLoans/Form', [
            'loan'        => null,
            'employees'   => $this->activeEmployees(),
            'shareholders' => $this->activeShareholders(),
            'funds'        => $this->activeFunds(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lender_type'    => 'required|in:employee,shareholder,other',
            'employee_id'    => 'nullable|exists:employees,id',
            'shareholder_id' => 'nullable|exists:shareholders,id',
            'lender_name'    => 'nullable|string|max:100',
            'amount'         => 'required|numeric|min:1',
            'interest_rate'  => 'nullable|numeric|min:0|max:100',
            'loan_date'      => 'required|date',
            'due_date'       => 'nullable|date|after_or_equal:loan_date',
            'purpose'        => 'nullable|string|max:255',
            'fund_id'        => 'required|exists:funds,id',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $loan = PersonalLoan::create(array_merge($data, [
            'loan_no'        => PersonalLoan::generateNo(),
            'repaid_amount'  => 0,
            'status'         => PersonalLoanStatus::Draft,
            'created_by'     => auth()->id(),
        ]));

        return redirect()->route('accounting.personal-loans.show', $loan)
            ->with('success', "Khoản vay {$loan->loan_no} đã được tạo.");
    }

    public function show(PersonalLoan $personalLoan): Response
    {
        $personalLoan->load('employee', 'shareholder', 'fund', 'creator', 'journalEntry',
            'repayments.fund', 'repayments.journalEntry');

        return Inertia::render('Accounting/PersonalLoans/Show', [
            'loan'  => $this->dto($personalLoan),
            'funds' => $this->activeFunds(),
        ]);
    }

    public function post(PersonalLoan $personalLoan): RedirectResponse
    {
        try {
            $this->service->post($personalLoan);
            return back()->with('success', "Khoản vay {$personalLoan->loan_no} đã được ghi sổ.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function repay(Request $request, PersonalLoan $personalLoan): RedirectResponse
    {
        $data = $request->validate([
            'fund_id'        => 'required|exists:funds,id',
            'repayment_date' => 'required|date',
            'amount'         => 'required|numeric|min:1',
            'description'    => 'nullable|string|max:255',
        ]);

        try {
            $this->service->addRepayment($personalLoan, $data);
            return back()->with('success', 'Đã ghi nhận đợt trả nợ.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(PersonalLoan $personalLoan): RedirectResponse
    {
        try {
            $this->service->cancel($personalLoan);
            return back()->with('success', "Khoản vay {$personalLoan->loan_no} đã bị hủy.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function dto(PersonalLoan $l): array
    {
        return [
            'id'              => $l->id,
            'loan_no'         => $l->loan_no,
            'lender_type'     => $l->lender_type,
            'lender_name'     => $l->lenderName(),
            'employee_id'     => $l->employee_id,
            'employee'        => $l->employee ? ['id' => $l->employee->id, 'name' => $l->employee->name] : null,
            'shareholder_id'  => $l->shareholder_id,
            'shareholder'     => $l->shareholder ? ['id' => $l->shareholder->id, 'name' => $l->shareholder->name] : null,
            'amount'          => (float) $l->amount,
            'repaid_amount'   => (float) $l->repaid_amount,
            'remaining'       => $l->remainingAmount(),
            'interest_rate'   => $l->interest_rate ? (float) $l->interest_rate : null,
            'loan_date'       => $l->loan_date->format('Y-m-d'),
            'loan_date_f'     => $l->loan_date->format('d/m/Y'),
            'due_date'        => $l->due_date?->format('Y-m-d'),
            'due_date_f'      => $l->due_date?->format('d/m/Y'),
            'purpose'         => $l->purpose,
            'fund_id'         => $l->fund_id,
            'fund'            => $l->fund ? ['id' => $l->fund->id, 'name' => $l->fund->name, 'account_code' => $l->fund->account_code] : null,
            'status'          => $l->status->value,
            'status_label'    => $l->status->label(),
            'status_color'    => $l->status->color(),
            'journal_entry'   => $l->journalEntry ? ['id' => $l->journalEntry->id, 'code' => $l->journalEntry->code] : null,
            'notes'           => $l->notes,
            'creator'         => $l->creator?->name,
            'created_at'      => $l->created_at->format('d/m/Y H:i'),
            'repayments'      => $l->repayments->map(fn ($r) => [
                'id'             => $r->id,
                'repayment_date' => $r->repayment_date->format('d/m/Y'),
                'amount'         => (float) $r->amount,
                'description'    => $r->description,
                'fund'           => $r->fund?->name,
                'je_code'        => $r->journalEntry?->code,
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
                'id'           => $f->id,
                'name'         => $f->name,
                'type'         => $f->type,
                'account_code' => $f->account_code,
                'balance'      => $f->balance(),
            ])->toArray();
    }
}
