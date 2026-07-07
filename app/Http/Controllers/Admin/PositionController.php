<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Position::withTrashed()->orderByDesc('id');

        if ($s = $request->input('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")
                ->orWhere('code', 'ilike', "%{$s}%"));
        }
        if ($request->boolean('inactive')) {
            $query->onlyTrashed();
        } elseif (!$request->boolean('all')) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }

        return Inertia::render('Admin/Positions/Index', [
            'positions' => $query->paginate(25)->through(fn (Position $p) => $this->dto($p)),
            'filters'      => $request->only(['search', 'inactive', 'all']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Positions/Form', ['position' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $p    = Position::create(array_merge($data, [
            'code'       => Position::generateCode(),
            'created_by' => auth()->id(),
        ]));
        return redirect()->route('admin.positions.index')
            ->with('success', "Chức vụ {$p->code} đã được thêm.");
    }

    public function edit(Position $position): Response
    {
        return Inertia::render('Admin/Positions/Form', ['position' => $this->dto($position)]);
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $position->update($this->validated($request));
        return redirect()->route('admin.positions.index')
            ->with('success', "Thông tin chức vụ {$position->code} đã được cập nhật.");
    }

    public function destroy(Position $position): RedirectResponse
    {
        $position->delete();
        return back()->with('success', "Chức vụ {$position->code} đã bị xóa.");
    }

    private function validated(Request $request): array
    {
        $positionId = $request->route('position')?->id;
        return $request->validate([
            'name'      => [
                'required',
                'string',
                'max:100',
                Rule::unique('positions', 'name')
                    ->ignore($positionId)
                    ->whereNull('deleted_at')
            ],
            'is_active' => 'boolean',
            'notes'     => 'nullable|string|max:1000',
        ]);
    }

    private function dto(Position $p): array
    {
        return [
            'id'         => $p->id,
            'code'       => $p->code,
            'name'       => $p->name,
            'is_active'  => $p->is_active,
            'notes'      => $p->notes,
            'deleted_at' => $p->deleted_at?->format('d/m/Y'),
        ];
    }
}
