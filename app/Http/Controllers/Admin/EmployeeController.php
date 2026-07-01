<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeExportService;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->input('q');
        $status = $request->input('status');

        $employees = Employee::with('creator')
            ->filter(['q' => $q, 'status' => $status])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($e) => [
                'id'              => $e->id,
                'code'            => $e->code,
                'name'            => $e->name,
                'department'      => $e->department,
                'position'        => $e->position,
                'phone'           => $e->phone,
                'hire_date'       => $e->hire_date?->format('d/m/Y'),
                'status'          => $e->status->value,
                'status_label'    => $e->status->label(),
                'status_color'    => $e->status->color(),
                'employment_type' => $e->employment_type->label(),
            ]);

        return Inertia::render('Admin/Employees/Index', [
            'employees' => $employees,
            'filters'   => ['q' => $q, 'status' => $status],
            'statuses'  => collect(EmployeeStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Employees/Form', [
            'nextCode'        => Employee::generateCode(),
            'statuses'        => collect(EmployeeStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'employmentTypes' => collect(EmploymentType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
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
        ];

        if ($forShow) {
            $dto['status_label']           = $employee->status->label();
            $dto['status_color']           = $employee->status->color();
            $dto['gender_label']           = $employee->gender === 'male' ? 'Nam' : ($employee->gender === 'female' ? 'Nữ' : null);
            $dto['employment_type_label']  = $employee->employment_type->label();
            $dto['creator']                = $employee->creator?->name;
            $dto['created_at']             = $employee->created_at->format('d/m/Y');
            $dto['total_allowances']       = (float) $employee->totalAllowances();
        }

        return $dto;
    }

    public function edit(Employee $employee): Response
    {
        return Inertia::render('Admin/Employees/Form', [
            'employee'        => $this->employeeDTO($employee, false),
            'statuses'        => collect(EmployeeStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'employmentTypes' => collect(EmploymentType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
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
        ]);

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

    private function coerceSalaryFields(array $data): array
    {
        foreach (['base_salary', 'allowance', 'allowance_responsibility', 'allowance_lunch', 'allowance_phone', 'allowance_transport'] as $field) {
            $data[$field] = (float) ($data[$field] ?? 0);
        }
        return $data;
    }
}
