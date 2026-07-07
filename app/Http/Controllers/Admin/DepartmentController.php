<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Department::withTrashed()->orderByDesc('id');

        if ($s = $request->input('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")
                ->orWhere('code', 'ilike', "%{$s}%"));
        }
        if ($request->boolean('inactive')) {
            $query->onlyTrashed();
        } elseif (!$request->boolean('all')) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }

        return Inertia::render('Admin/Departments/Index', [
            'departments' => $query->paginate(25)->through(fn (Department $d) => $this->dto($d)),
            'filters'      => $request->only(['search', 'inactive', 'all']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Departments/Form', ['department' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $d    = Department::create(array_merge($data, [
            'code'       => Department::generateCode(),
            'created_by' => auth()->id(),
        ]));
        return redirect()->route('admin.departments.index')
            ->with('success', "Bộ phận {$d->code} đã được thêm.");
    }

    public function edit(Department $department): Response
    {
        return Inertia::render('Admin/Departments/Form', ['department' => $this->dto($department)]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request));
        return redirect()->route('admin.departments.index')
            ->with('success', "Thông tin bộ phận {$department->code} đã được cập nhật.");
    }

    public function destroy(Department $department): RedirectResponse
    {
        $department->delete();
        return back()->with('success', "Bộ phận {$department->code} đã bị xóa.");
    }

    private function validated(Request $request): array
    {
        $departmentId = $request->route('department')?->id;
        return $request->validate([
            'name'      => [
                'required',
                'string',
                'max:100',
                Rule::unique('departments', 'name')
                    ->ignore($departmentId)
                    ->whereNull('deleted_at')
            ],
            'is_active' => 'boolean',
            'notes'     => 'nullable|string|max:1000',
        ]);
    }

    private function dto(Department $d): array
    {
        return [
            'id'         => $d->id,
            'code'       => $d->code,
            'name'       => $d->name,
            'is_active'  => $d->is_active,
            'notes'      => $d->notes,
            'deleted_at' => $d->deleted_at?->format('d/m/Y'),
        ];
    }
}
