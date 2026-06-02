<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->input('q');
        $status = $request->input('status');

        $employees = Employee::with('creator')
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('name', 'ilike', "%{$q}%")
                  ->orWhere('code', 'ilike', "%{$q}%")
                  ->orWhere('department', 'ilike', "%{$q}%")
                  ->orWhere('position', 'ilike', "%{$q}%");
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
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
            'code'             => ['required', 'string', 'max:20', 'unique:employees,code'],
            'name'             => ['required', 'string', 'max:255'],
            'department'       => ['nullable', 'string', 'max:100'],
            'position'         => ['nullable', 'string', 'max:100'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'email'            => ['nullable', 'email', 'max:255'],
            'birth_date'       => ['nullable', 'date'],
            'gender'           => ['nullable', 'in:male,female'],
            'hire_date'        => ['nullable', 'date'],
            'status'           => ['required', 'in:' . implode(',', array_column(EmployeeStatus::cases(), 'value'))],
            'employment_type'  => ['required', 'in:' . implode(',', array_column(EmploymentType::cases(), 'value'))],
            'base_salary'      => ['nullable', 'numeric', 'min:0'],
            'allowance'        => ['nullable', 'numeric', 'min:0'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'pit_tax_code'     => ['nullable', 'string', 'max:20'],
            'address'          => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
        ]);

        $employee = Employee::create([...$data, 'created_by' => auth()->id()]);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã thêm cán bộ.');
    }

    public function show(Employee $employee): Response
    {
        return Inertia::render('Admin/Employees/Show', [
            'employee' => [
                'id'              => $employee->id,
                'code'            => $employee->code,
                'name'            => $employee->name,
                'department'      => $employee->department,
                'position'        => $employee->position,
                'phone'           => $employee->phone,
                'email'           => $employee->email,
                'birth_date'      => $employee->birth_date?->format('d/m/Y'),
                'gender'          => $employee->gender,
                'gender_label'    => $employee->gender === 'male' ? 'Nam' : ($employee->gender === 'female' ? 'Nữ' : null),
                'hire_date'       => $employee->hire_date?->format('d/m/Y'),
                'status'          => $employee->status->value,
                'status_label'    => $employee->status->label(),
                'status_color'    => $employee->status->color(),
                'employment_type' => $employee->employment_type->value,
                'employment_type_label' => $employee->employment_type->label(),
                'base_salary'     => (float) $employee->base_salary,
                'allowance'       => (float) $employee->allowance,
                'dependents_count'=> (int)   $employee->dependents_count,
                'pit_tax_code'    => $employee->pit_tax_code,
                'address'         => $employee->address,
                'notes'           => $employee->notes,
                'creator'         => $employee->creator->name,
                'created_at'      => $employee->created_at->format('d/m/Y'),
            ],
        ]);
    }

    public function edit(Employee $employee): Response
    {
        return Inertia::render('Admin/Employees/Form', [
            'employee' => [
                'id'               => $employee->id,
                'code'             => $employee->code,
                'name'             => $employee->name,
                'department'       => $employee->department,
                'position'         => $employee->position,
                'phone'            => $employee->phone,
                'email'            => $employee->email,
                'birth_date'       => $employee->birth_date?->format('Y-m-d'),
                'gender'           => $employee->gender,
                'hire_date'        => $employee->hire_date?->format('Y-m-d'),
                'status'           => $employee->status->value,
                'employment_type'  => $employee->employment_type->value,
                'base_salary'      => (float) $employee->base_salary,
                'allowance'        => (float) $employee->allowance,
                'dependents_count' => (int)   $employee->dependents_count,
                'pit_tax_code'     => $employee->pit_tax_code,
                'address'          => $employee->address,
                'notes'            => $employee->notes,
            ],
            'statuses'        => collect(EmployeeStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'employmentTypes' => collect(EmploymentType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'department'       => ['nullable', 'string', 'max:100'],
            'position'         => ['nullable', 'string', 'max:100'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'email'            => ['nullable', 'email', 'max:255'],
            'birth_date'       => ['nullable', 'date'],
            'gender'           => ['nullable', 'in:male,female'],
            'hire_date'        => ['nullable', 'date'],
            'status'           => ['required', 'in:' . implode(',', array_column(EmployeeStatus::cases(), 'value'))],
            'employment_type'  => ['required', 'in:' . implode(',', array_column(EmploymentType::cases(), 'value'))],
            'base_salary'      => ['nullable', 'numeric', 'min:0'],
            'allowance'        => ['nullable', 'numeric', 'min:0'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'pit_tax_code'     => ['nullable', 'string', 'max:20'],
            'address'          => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
        ]);

        $employee->update($data);

        return redirect()->route('admin.employees.show', $employee)
            ->with('success', 'Đã cập nhật.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('admin.employees.index')
            ->with('success', 'Đã xóa cán bộ.');
    }
}
