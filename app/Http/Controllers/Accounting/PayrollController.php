<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\PayrollService;
use App\Services\PitCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService       $service,
        private PitCalculatorService $pit,
    ) {}

    public function index(Request $request): Response
    {
        $query = Payroll::with('creator')->orderByDesc('period');

        $payrolls = $query->paginate(20)->through(fn (Payroll $p) => [
            'id'                => $p->id,
            'code'              => $p->code,
            'period'            => $p->period,
            'status'            => $p->status->value,
            'status_label'      => $p->status->label(),
            'status_color'      => $p->status->color(),
            'total_base_salary' => (float) $p->total_base_salary,
            'total_allowance'   => (float) $p->total_allowance,
            'total_bonus'       => (float) $p->total_bonus,
            'total_deductions'  => (float) $p->total_deductions,
            'total_net_salary'  => (float) $p->total_net_salary,
            'creator'           => $p->creator?->name,
            'created_at'        => $p->created_at->format('d/m/Y'),
        ]);

        return Inertia::render('Accounting/Payrolls/Index', [
            'payrolls' => $payrolls,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Payrolls/Form', [
            'payroll' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'notes'  => 'nullable|string|max:500',
        ]);

        try {
            $this->service->createPayroll($data['period'], $data['notes'] ?? null);
            return redirect()->route('accounting.payrolls.index')
                ->with('success', 'Bảng lương tháng đã được lập thành công.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function show(Payroll $payroll): Response
    {
        $payroll->load('creator', 'locker');

        $items = $payroll->items()->with('employee', 'user', 'salaryJournalEntry')
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollItem $item) => $this->itemDTO($item));

        $bankAccounts = BankAccount::orderBy('bank_name')
            ->get(['id', 'bank_name', 'account_number', 'account_name', 'account_code']);

        return Inertia::render('Accounting/Payrolls/Show', [
            'payroll' => [
                'id'                        => $payroll->id,
                'code'                      => $payroll->code,
                'period'                    => $payroll->period,
                'status'                    => $payroll->status->value,
                'status_label'              => $payroll->status->label(),
                'status_color'              => $payroll->status->color(),
                'total_base_salary'         => (float) $payroll->total_base_salary,
                'total_allowance'           => (float) $payroll->total_allowance,
                'total_bonus'               => (float) $payroll->total_bonus,
                'total_gross'               => (float) $payroll->total_gross,
                'total_insurance_employee'  => (float) $payroll->total_insurance_employee,
                'total_insurance_employer'  => (float) $payroll->total_insurance_employer,
                'total_pit'                 => (float) $payroll->total_pit,
                'total_deductions'          => (float) $payroll->total_deductions,
                'total_net_salary'          => (float) $payroll->total_net_salary,
                'creator'                   => $payroll->creator?->name,
                'notes'                     => $payroll->notes,
                'created_at'                => $payroll->created_at->format('d/m/Y H:i'),
                'is_locked'                 => (bool) $payroll->is_locked,
                'locked_by_name'            => $payroll->locker?->name,
                'locked_at'                 => $payroll->locked_at?->format('d/m/Y H:i'),
            ],
            'items'        => $items,
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function updateItem(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể chỉnh sửa.');
        }

        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể chỉnh sửa lương khi bảng lương ở trạng thái nháp.');
        }

        if ($item->payroll_id !== $payroll->id) {
            return back()->with('error', 'Dòng lương không thuộc bảng lương này.');
        }

        $data = $request->validate([
            'base_salary'              => 'required|numeric|min:0',
            'allowance_responsibility' => 'nullable|numeric|min:0',
            'allowance_lunch'          => 'nullable|numeric|min:0',
            'allowance_phone'          => 'nullable|numeric|min:0',
            'allowance_transport'      => 'nullable|numeric|min:0',
            'allowance_performance'    => 'nullable|numeric|min:0',
            'allowance'                => 'nullable|numeric|min:0',
            'bonus'                    => 'nullable|numeric|min:0',
            'working_days'             => 'nullable|integer|min:0|max:31',
            'advance'                  => 'nullable|numeric|min:0',
            'insurance_subject'        => 'nullable|boolean',
            'dependents_count'         => 'nullable|integer|min:0|max:10',
        ]);

        $base           = (float) $data['base_salary'];
        $allocResp      = (float) ($data['allowance_responsibility'] ?? $item->allowance_responsibility ?? 0);
        $allocLunch     = (float) ($data['allowance_lunch']          ?? $item->allowance_lunch          ?? 0);
        $allocPhone     = (float) ($data['allowance_phone']          ?? $item->allowance_phone          ?? 0);
        $allocTransport = (float) ($data['allowance_transport']      ?? $item->allowance_transport      ?? 0);
        $allocPerf      = (float) ($data['allowance_performance']    ?? $item->allowance_performance    ?? 0);
        $allocOther     = (float) ($data['allowance']                ?? $item->allowance                ?? 0);
        $bonus          = (float) ($data['bonus']                    ?? 0);
        $totalAllw      = $allocResp + $allocLunch + $allocPhone + $allocTransport + $allocPerf + $allocOther;
        $dependents     = (int)   ($data['dependents_count']         ?? $item->dependents_count         ?? 0);
        $insSubject     = (bool)  ($data['insurance_subject']        ?? $item->insurance_subject        ?? true);
        $workingDays    = (int)   ($data['working_days']             ?? $item->working_days             ?? $item->standard_days ?? 26);
        $standardDays   = (int)   ($item->standard_days              ?? 26);

        // Phân loại theo Nghị định 158/2025:
        //   BHXH-subject  : allocResp + allocOther (phụ cấp lương ổn định trong HĐLĐ)
        //   Non-BHXH      : ăn trưa + xăng xe + ĐT + hiệu quả CV + bonus (phúc lợi/biến động)
        $bhxhAllowances    = $allocResp + $allocOther;
        $nonBhxhAllowances = $allocLunch + $allocPhone + $allocTransport + $allocPerf + $bonus;

        $bd = $this->pit->breakdown($base, $bhxhAllowances, $nonBhxhAllowances, $dependents, $insSubject, $workingDays, $standardDays);

        $item->update([
            'base_salary'              => $base,
            'allowance'                => $allocOther,
            'allowance_responsibility' => $allocResp,
            'allowance_lunch'          => $allocLunch,
            'allowance_phone'          => $allocPhone,
            'allowance_transport'      => $allocTransport,
            'allowance_performance'    => $allocPerf,
            'bonus'                    => $bonus,
            'gross_salary'             => $bd['gross_salary'],
            'insurance_base'           => $bd['insurance_base'],
            'bhxh_employee'            => $bd['bhxh_employee'],
            'bhyt_employee'            => $bd['bhyt_employee'],
            'bhtn_employee'            => $bd['bhtn_employee'],
            'bhxh_employer'            => $bd['bhxh_employer'],
            'bhyt_employer'            => $bd['bhyt_employer'],
            'bhtn_employer'            => $bd['bhtn_employer'],
            'pit'                      => $bd['pit'],
            'dependents_count'         => $dependents,
            'deductions'               => $bd['ins_employee'] + $bd['pit'],
            'net_salary'               => $bd['net_salary'],
            'working_days'             => $workingDays,
            'advance'                  => (float) ($data['advance'] ?? $item->advance ?? 0),
            'insurance_subject'        => $insSubject,
        ]);

        $this->service->recalculateTotals($payroll);

        return back()->with('success', 'Đã cập nhật lương của nhân viên.');
    }

    private function itemDTO(PayrollItem $item): array
    {
        $item->loadMissing('salaryJournalEntry');
        $gross       = (float) $item->gross_salary;
        $insEmp      = (float) $item->bhxh_employee + (float) $item->bhyt_employee + (float) $item->bhtn_employee;
        $personalDed = \App\Services\PitCalculatorService::PERSONAL_DEDUCTION
            + ((int) $item->dependents_count * \App\Services\PitCalculatorService::DEPENDENT_DEDUCTION);

        return [
            'id'                       => $item->id,
            'employee_name'            => $item->employee?->name ?? $item->user?->name,
            'employee_code'            => $item->employee?->code,
            'department'               => $item->employee?->department,
            'position'                 => $item->employee?->position ?? $item->user?->roles?->first()?->name ?? 'Nhân viên',
            'pit_tax_code'             => $item->employee?->pit_tax_code ?? $item->user?->pit_tax_code,
            'base_salary'              => (float) $item->base_salary,
            'allowance'                => (float) $item->allowance,
            'allowance_responsibility' => (float) ($item->allowance_responsibility ?? 0),
            'allowance_lunch'          => (float) ($item->allowance_lunch          ?? 0),
            'allowance_phone'          => (float) ($item->allowance_phone          ?? 0),
            'allowance_transport'      => (float) ($item->allowance_transport      ?? 0),
            'allowance_performance'    => (float) ($item->allowance_performance    ?? 0),
            'bonus'                    => (float) $item->bonus,
            'gross_salary'             => $gross,
            'insurance_base'           => (float) $item->insurance_base,
            'bhxh_employee'            => (float) $item->bhxh_employee,
            'bhyt_employee'            => (float) $item->bhyt_employee,
            'bhtn_employee'            => (float) $item->bhtn_employee,
            'bhxh_employer'            => (float) $item->bhxh_employer,
            'bhyt_employer'            => (float) $item->bhyt_employer,
            'bhtn_employer'            => (float) $item->bhtn_employer,
            'pit'                      => (float) $item->pit,
            'dependents_count'         => (int)   $item->dependents_count,
            'personal_deduction'       => round($personalDed),
            'taxable_for_pit'          => max(0, round($gross - $insEmp - $personalDed)),
            'deductions'               => (float) $item->deductions,
            'net_salary'               => (float) $item->net_salary,
            'working_days'             => (int)   ($item->working_days  ?? 26),
            'standard_days'            => (int)   ($item->standard_days ?? 26),
            'advance'                  => (float) ($item->advance       ?? 0),
            'insurance_subject'        => (bool)  ($item->insurance_subject ?? true),
            'thuc_linh'                => max(0, (float) $item->net_salary - (float) ($item->advance ?? 0)),
            'status'                   => $item->status->value,
            'status_label'             => $item->status->label(),
            'status_color'             => $item->status->color(),
            'paid_at'                  => $item->paid_at?->format('d/m/Y H:i'),
            'salary_journal_entry'     => $item->salaryJournalEntry ? [
                'id'   => $item->salaryJournalEntry->id,
                'code' => $item->salaryJournalEntry->code,
            ] : null,
        ];
    }

    public function lock(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã được khóa.');
        }

        $payroll->update([
            'is_locked' => true,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);

        return back()->with('success', "Bảng lương {$payroll->code} đã được khóa.");
    }

    public function unlock(Payroll $payroll): RedirectResponse
    {
        if (!auth()->user()->hasRole('admin')) {
            return back()->with('error', 'Chỉ Admin mới có thể mở khóa bảng lương.');
        }

        if (!$payroll->is_locked) {
            return back()->with('error', 'Bảng lương chưa bị khóa.');
        }

        $payroll->update([
            'is_locked' => false,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        return back()->with('success', "Đã mở khóa bảng lương {$payroll->code}.");
    }

    public function confirm(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể xác nhận.');
        }

        try {
            $this->service->confirmPayroll($payroll);
            return back()->with('success', 'Bảng lương tháng đã được xác nhận.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function payEmployee(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
        ]);
        try {
            $this->service->payEmployeeSalary($item, $data['bank_account_id']);
            $employeeName = $item->employee?->name ?? $item->user?->name ?? 'nhân viên';
            return back()->with('success', "Đã thanh toán lương cho {$employeeName}.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể xóa.');
        }

        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể xóa bảng lương ở trạng thái nháp.');
        }

        $payroll->delete();

        return redirect()->route('accounting.payrolls.index')
            ->with('success', 'Bảng lương tháng đã được xóa.');
    }
}
