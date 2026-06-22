<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shareholder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShareholderController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Shareholder::withTrashed()->orderByDesc('id');

        if ($s = $request->input('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")
                ->orWhere('code', 'ilike', "%{$s}%"));
        }
        if ($request->boolean('inactive')) {
            $query->onlyTrashed();
        } elseif (! $request->boolean('all')) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }

        return Inertia::render('Admin/Shareholders/Index', [
            'shareholders' => $query->paginate(25)->through(fn (Shareholder $s) => $this->dto($s)),
            'filters'      => $request->only(['search', 'inactive', 'all']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Shareholders/Form', ['shareholder' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $sh   = Shareholder::create(array_merge($data, [
            'code'       => Shareholder::generateCode(),
            'created_by' => auth()->id(),
        ]));
        return redirect()->route('admin.shareholders.index')
            ->with('success', "Thành viên {$sh->code} đã được thêm.");
    }

    public function edit(Shareholder $shareholder): Response
    {
        return Inertia::render('Admin/Shareholders/Form', ['shareholder' => $this->dto($shareholder)]);
    }

    public function update(Request $request, Shareholder $shareholder): RedirectResponse
    {
        $shareholder->update($this->validated($request));
        return redirect()->route('admin.shareholders.index')
            ->with('success', "Thông tin thành viên {$shareholder->code} đã được cập nhật.");
    }

    public function destroy(Shareholder $shareholder): RedirectResponse
    {
        $shareholder->delete();
        return back()->with('success', "Thành viên {$shareholder->code} đã bị xóa.");
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'              => 'required|string|max:100',
            'id_number'         => 'nullable|string|max:20',
            'tax_number'        => 'nullable|string|max:20',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'nullable|email|max:100',
            'address'           => 'nullable|string|max:500',
            'share_percentage'  => 'nullable|numeric|min:0|max:100',
            'is_active'         => 'boolean',
            'notes'             => 'nullable|string|max:1000',
        ]);
    }

    private function dto(Shareholder $s): array
    {
        return [
            'id'               => $s->id,
            'code'             => $s->code,
            'name'             => $s->name,
            'id_number'        => $s->id_number,
            'tax_number'       => $s->tax_number,
            'phone'            => $s->phone,
            'email'            => $s->email,
            'address'          => $s->address,
            'share_percentage' => $s->share_percentage ? (float) $s->share_percentage : null,
            'is_active'        => $s->is_active,
            'notes'            => $s->notes,
            'deleted_at'       => $s->deleted_at?->format('d/m/Y'),
        ];
    }
}
