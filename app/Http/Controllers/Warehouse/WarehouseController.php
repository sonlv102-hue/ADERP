<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Warehouse/Warehouses/Index', [
            'warehouses' => Warehouse::orderBy('name')->paginate(20)
                ->through(fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'address' => $w->address,
                    'phone' => $w->phone,
                    'is_active' => $w->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/Warehouses/Form');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        Warehouse::create($data);

        return redirect()->route('warehouse.warehouses.index')
            ->with('success', 'Đã tạo kho hàng.');
    }

    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('Warehouse/Warehouses/Form', [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'address' => $warehouse->address,
                'phone' => $warehouse->phone,
                'is_active' => $warehouse->is_active,
            ],
        ]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);

        $warehouse->update($data);

        return redirect()->route('warehouse.warehouses.index')
            ->with('success', 'Đã cập nhật kho hàng.');
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $warehouse->delete();

        return redirect()->route('warehouse.warehouses.index')
            ->with('success', 'Đã xóa kho hàng.');
    }
}
