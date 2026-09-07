<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Department;
use App\Models\Position;
use App\Services\EmployeeExportService;
use App\Services\EmployeeTerminationService;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->input('q');
        $status = $request->input('status'); // '' => đang làm việc (mặc định) | 'all' => tất cả | enum value

        $needle = $q ? mb_strtolower($q) : null;
        $query = Employee::with('creator')
            ->when($needle, fn ($sub) => $sub->where(function ($sq) use ($needle) {
                $sq->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$needle}%"])
                   ->orWhereRaw('LOWER(COALESCE(department, \'\')) LIKE ?', ["%{$needle}%"])
                   ->orWhereRaw('LOWER(COALESCE(position, \'\')) LIKE ?', ["%{$needle}%"]);
            }))
            ->orderBy('name');

        if ($status === null || $status === '') {
            $query->working();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $employees = $query->paginate(20)->withQueryString()->through(fn ($e) => [
            'id'               => $e->id,
            'code'             => $e->code,
            'name'             => $e->name,
            'department'       => $e->department,
            'position'         => $e->position,
            'phone'            => $e->phone,
            'hire_date'        => $e->hire_date?->format('d/m/Y'),
            'termination_date' => $e->termination_date?->format('d/m/Y'),
            'status'           => $e->status->value,
            'status_label'     => $e->status->label(),
            'status_color'     => $e->status->color(),
            'employment_type'  => $e->employment_type->label(),
        ]);

        return Inertia::render('Admin/Employees/Index', [
            'employees' => $employees,
            'filters'   => ['q' => $q, 'status' => $status],
            'statuses'  => array_merge(
                [
                    ['value' => '',    'label' => 'Đang làm việc'],
                    ['value' => 'all', 'label' => 'Tất cả'],
                ],
                collect(EmployeeStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Employees/Form', [
            'nextCode'        => Employee::generateCode(),
            'statuses'        => collect(EmployeeStatus::cases())
                ->filter(fn ($s) => $s->isWorking())
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'employmentTypes' => collect(EmploymentType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'departments'     => Department::where('is_active', true)->orderBy('name')->pluck('name')->toArray(),
            'positions'       => Position::where('is_active', true)->orderBy('name')->pluck('name')->toArray(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                     => ['required', 'string', 'max:20', 'unique:employees,code'],
            'name'                     => ['required', 'string', 'max:255'],
            'department'               => ['nullable', 'string', 'max:100'],
            'position'                 => ['nullable', 'string', 'max:100'],
            'phone'                    => ['nullable', 'string', 'max:20'],
            'email'                    => ['nullable', 'email', 'max:255'],
            'birth_date'               => ['nullable', 'date'],
            'gender'                   => ['nullable', 'in:male,female'],
            'hire_date'                => ['nullable', 'date'],
            'status'                   => ['required', 'in:' . implode(',', array_column(EmployeeStatus::cases(), 'value'))],
            'employment_type'          => ['required', 'in:' . implode(',', array_column(EmploymentType::cases(), 'value'))],
            'base_salary'              => ['nullable', 'numeric', 'min:0'],
            'allowance'                => ['nullable', 'numeric', 'min:0'],
            'allowance_responsibility' => ['nullable', 'numeric', 'min:0'],
            'allowance_lunch'          => ['nullable', 'numeric', 'min:0'],
            'allowance_phone'          => ['nullable', 'numeric', 'min:0'],
            'allowance_transport'      => ['nullable', 'numeric', 'min:0'],
            'insurance_subject'        => ['nullable', 'boolean'],
            'standard_days'            => ['nullable', 'integer', 'min:20', 'max:31'],
            'dependents_count'         => ['nullable', 'integer', 'min:0', 'max:20'],
            'pit_tax_code'             => ['nullable', 'string', 'max:20'],
            'address'                  => ['nullable', 'string'],
            'notes'                    => ['nullable', 'string'],
            'national_id'              => ['nullable', 'string', 'max:20'],
            'national_id_issue_date'   => ['nullable', 'date'],
            'national_id_issue_place'  => ['nullable', 'string', 'max:255'],
            'contract_start_date'      => ['nullable', 'date'],
            'contract_end_date'        => ['nullable', 'date'],
            'social_insurance_no'      => ['nullable', 'string', 'max:20'],
            'bank_account_no'          => ['nullable', 'string', 'max:30'],
            'bank_name'                => ['nullable', 'string', 'max:100'],
        ]);

        $data = $this->coerceSalaryFields($data);
        $employee = Employee::create([...$data, 'created_by' => auth()->id()]);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã thêm cán bộ.');
    }

    public function show(Employee $employee): Response
    {
        $employee->loadMissing('attachments');

        return Inertia::render('Admin/Employees/Show', [
            'employee'    => $this->employeeDTO($employee, true),
            'attachments' => $employee->attachments->map(fn ($a) => [
                'id' => $a->id, 'file_name' => $a->file_name, 'file_path' => $a->file_path,
                'file_size' => $a->file_size, 'created_at' => $a->created_at->format('d/m/Y'),
            ]),
        ]);
    }

    public function exportExcel(Request $request, EmployeeExportService $service)
    {
        $filters = $request->only(['q', 'status']);
        $export  = $service->exportListExcel($filters);

        return Excel::download($export, $service->listExcelFilename());
    }

    public function exportPdf(Employee $employee, EmployeeExportService $service)
    {
        return $service->exportProfilePdf($employee)->download("Ho-so-{$employee->code}.pdf");
    }

    public function printProfile(Employee $employee, EmployeeExportService $service)
    {
        return $service->renderPrintProfile($employee);
    }

    private function employeeDTO(Employee $employee, bool $forShow = false): array
    {
        $dto = [
            'id'                       => $employee->id,
            'code'                     => $employee->code,
            'name'                     => $employee->name,
            'department'               => $employee->department,
            'position'                 => $employee->position,
            'phone'                    => $employee->phone,
            'email'                    => $employee->email,
            'gender'                   => $employee->gender,
            'hire_date'                => $employee->hire_date?->format($forShow ? 'd/m/Y' : 'Y-m-d'),
            'birth_date'               => $employee->birth_date?->format($forShow ? 'd/m/Y' : 'Y-m-d'),
            'status'                   => $employee->status->value,
            'employment_type'          => $employee->employment_type->value,
            'base_salary'              => (float) $employee->base_salary,
            'allowance'                => (float) $employee->allowance,
            'allowance_responsibility' => (float) ($employee->allowance_responsibility ?? 0),
            'allowance_lunch'          => (float) ($employee->allowance_lunch          ?? 0),
            'allowance_phone'          => (float) ($employee->allowance_phone          ?? 0),
            'allowance_transport'      => (float) ($employee->allowance_transport      ?? 0),
            'insurance_subject'        => (bool)  ($employee->insurance_subject        ?? true),
            'standard_days'            => (int)   ($employee->standard_days            ?? 26),
            'dependents_count'         => (int)   $employee->dependents_count,
            'pit_tax_code'             => $employee->pit_tax_code,
            'address'                  => $employee->address,
            'notes'                    => $employee->notes,
            'national_id'              => $employee->national_id,
            'national_id_issue_date'   => $employee->national_id_issue_date?->format($forShow ? 'd/m/Y' : 'Y-m-d'),
            'national_id_issue_place'  => $employee->national_id_issue_place,
            'contract_start_date'      => $employee->contract_start_date?->format($forShow ? 'd/m/Y' : 'Y-m-d'),
            'contract_end_date'        => $employee->contract_end_date?->format($forShow ? 'd/m/Y' : 'Y-m-d'),
            'social_insurance_no'      => $employee->social_insurance_no,
            'bank_account_no'          => $employee->bank_account_no,
            'bank_name'                => $employee->bank_name,
            'is_working'               => $employee->status->isWorking(),
            'status_label'             => $employee->status->label(),
        ];

        if ($forShow) {
            $dto['status_color']           = $employee->status->color();
            $dto['gender_label']           = $employee->gender === 'male' ? 'Nam' : ($employee->gender === 'female' ? 'Nữ' : null);
            $dto['employment_type_label']  = $employee->employment_type->label();
            $dto['creator']                = $employee->creator?->name;
            $dto['created_at']             = $employee->created_at->format('d/m/Y');
            $dto['total_allowances']       = (float) $employee->totalAllowances();
            $dto['termination_date']       = $employee->termination_date?->format('d/m/Y');
            $dto['termination_reason']     = $employee->termination_reason;
            $dto['termination_note']       = $employee->termination_note;
            $dto['termination_decision_no']   = $employee->termination_decision_no;
            $dto['termination_decision_date'] = $employee->termination_decision_date?->format('d/m/Y');
            $dto['terminated_by_name']     = $employee->terminatedBy?->name;
            $dto['terminated_at']          = $employee->terminated_at?->format('d/m/Y H:i');
        }

        return $dto;
    }

    public function edit(Employee $employee): Response
    {
        $employeeDept = $employee->department;
        $departments = Department::where('is_active', true)->orderBy('name')->pluck('name')->toArray();
        if ($employeeDept && !in_array($employeeDept, $departments)) {
            $departments[] = $employeeDept;
        }

        $employeePos = $employee->position;
        $positions = Position::where('is_active', true)->orderBy('name')->pluck('name')->toArray();
        if ($employeePos && !in_array($employeePos, $positions)) {
            $positions[] = $employeePos;
        }

        return Inertia::render('Admin/Employees/Form', [
            'employee'        => $this->employeeDTO($employee, false),
            'statuses'        => collect(EmployeeStatus::cases())
                ->filter(fn ($s) => $s->isWorking())
                ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])
                ->values(),
            'employmentTypes' => collect(EmploymentType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'departments'     => $departments,
            'positions'       => $positions,
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:255'],
            'department'               => ['nullable', 'string', 'max:100'],
            'position'                 => ['nullable', 'string', 'max:100'],
            'phone'                    => ['nullable', 'string', 'max:20'],
            'email'                    => ['nullable', 'email', 'max:255'],
            'birth_date'               => ['nullable', 'date'],
            'gender'                   => ['nullable', 'in:male,female'],
            'hire_date'                => ['nullable', 'date'],
            'status'                   => ['required', 'in:' . implode(',', array_column(EmployeeStatus::cases(), 'value'))],
            'employment_type'          => ['required', 'in:' . implode(',', array_column(EmploymentType::cases(), 'value'))],
            'base_salary'              => ['nullable', 'numeric', 'min:0'],
            'allowance'                => ['nullable', 'numeric', 'min:0'],
            'allowance_responsibility' => ['nullable', 'numeric', 'min:0'],
            'allowance_lunch'          => ['nullable', 'numeric', 'min:0'],
            'allowance_phone'          => ['nullable', 'numeric', 'min:0'],
            'allowance_transport'      => ['nullable', 'numeric', 'min:0'],
            'insurance_subject'        => ['nullable', 'boolean'],
            'standard_days'            => ['nullable', 'integer', 'min:20', 'max:31'],
            'dependents_count'         => ['nullable', 'integer', 'min:0', 'max:20'],
            'pit_tax_code'             => ['nullable', 'string', 'max:20'],
            'address'                  => ['nullable', 'string'],
            'notes'                    => ['nullable', 'string'],
            'national_id'              => ['nullable', 'string', 'max:20'],
            'national_id_issue_date'   => ['nullable', 'date'],
            'national_id_issue_place'  => ['nullable', 'string', 'max:255'],
            'contract_start_date'      => ['nullable', 'date'],
            'contract_end_date'        => ['nullable', 'date'],
            'social_insurance_no'      => ['nullable', 'string', 'max:20'],
            'bank_account_no'          => ['nullable', 'string', 'max:30'],
            'bank_name'                => ['nullable', 'string', 'max:100'],
        ]);

        // Trạng thái thôi việc chỉ được đổi qua nút "Thôi việc" / "Hủy thôi việc" (có ghi ngày + audit)
        if (in_array($data['status'], EmployeeStatus::endedValues(), true) && $employee->status->isWorking()) {
            return back()->with('error', 'Dùng nút "Thôi việc" ở màn chi tiết để ghi nhận nhân viên nghỉ.');
        }
        if (!$employee->status->isWorking()) {
            $data['status'] = $employee->status->value; // NV đã nghỉ: giữ nguyên, phải dùng "Hủy thôi việc"
        }

        $employee->update($this->coerceSalaryFields($data));

        app(PayrollService::class)->syncEmployeeToDraftPayrolls($employee);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã cập nhật.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('admin.employees.index')
            ->with('success', 'Đã xóa cán bộ.');
    }

    public function terminate(Request $request, Employee $employee, EmployeeTerminationService $service): RedirectResponse
    {
        $data = $request->validate([
            'termination_date'          => ['required', 'date'],
            'termination_reason'        => ['nullable', 'string', 'max:255'],
            'termination_note'          => ['nullable', 'string', 'max:2000'],
            'termination_decision_no'   => ['nullable', 'string', 'max:100'],
            'termination_decision_date' => ['nullable', 'date'],
        ]);

        try {
            $warning = $service->terminate($employee, $data);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã ghi nhận nhân viên thôi việc.')
            ->with($warning ? ['warning' => $warning] : []);
    }

    public function cancelTermination(Request $request, Employee $employee, EmployeeTerminationService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $service->cancelTermination($employee, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã hủy xác nhận thôi việc. Nhân viên trở lại trạng thái đang làm việc.');
    }

    public function headcount(Request $request): Response
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->endOfMonth()->toDateString());

        $ended = EmployeeStatus::endedValues();

        $openingCount = Employee::query()
            ->where(fn ($w) => $w->whereNull('hire_date')->orWhereDate('hire_date', '<', $from))
            ->where(fn ($w) => $w->whereNull('termination_date')->orWhereDate('termination_date', '>=', $from))
            ->where(fn ($w) => $w->whereNotIn('status', $ended)->orWhereNotNull('termination_date'))
            ->count();

        $increaseList = Employee::whereBetween('hire_date', [$from, $to])->orderBy('hire_date')
            ->get(['id', 'code', 'name', 'department', 'hire_date']);

        $decreaseList = Employee::whereBetween('termination_date', [$from, $to])->orderBy('termination_date')
            ->get(['id', 'code', 'name', 'department', 'termination_date', 'termination_reason']);

        $closingCount = Employee::query()
            ->where(fn ($w) => $w->whereNull('hire_date')->orWhereDate('hire_date', '<=', $to))
            ->where(fn ($w) => $w->whereNull('termination_date')->orWhereDate('termination_date', '>', $to))
            ->where(fn ($w) => $w->whereNotIn('status', $ended)->orWhereNotNull('termination_date'))
            ->count();

        return Inertia::render('Admin/Employees/Headcount', [
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'opening'  => $openingCount,
                'increase' => $increaseList->count(),
                'decrease' => $decreaseList->count(),
                'closing'  => $closingCount,
            ],
            'increase_list' => $increaseList->map(fn ($e) => [
                'code' => $e->code, 'name' => $e->name, 'department' => $e->department,
                'date' => $e->hire_date?->format('d/m/Y'),
            ]),
            'decrease_list' => $decreaseList->map(fn ($e) => [
                'code' => $e->code, 'name' => $e->name, 'department' => $e->department,
                'date' => $e->termination_date?->format('d/m/Y'), 'reason' => $e->termination_reason,
            ]),
        ]);
    }

    private function coerceSalaryFields(array $data): array
    {
        foreach (['base_salary', 'allowance', 'allowance_responsibility', 'allowance_lunch', 'allowance_phone', 'allowance_transport'] as $field) {
            $data[$field] = (float) ($data[$field] ?? 0);
        }
        return $data;
    }
}
