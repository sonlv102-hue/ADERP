<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\InventoryCountStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\Warehouse;
use App\Services\InventoryCountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryCountController extends Controller
{
    public function __construct(private InventoryCountService $svc) {}

    public function index(): Response
    {
        return Inertia::render('Warehouse/InventoryCounts/Index', [
            'counts' => InventoryCount::with(['warehouse', 'countedBy'])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($c) => [
                    'id'           => $c->id,
                    'code'         => $c->code,
                    'warehouse'    => $c->warehouse->name,
                    'count_date'   => $c->count_date->format('d/m/Y'),
                    'status'       => $c->status->value,
                    'status_label' => $c->status->label(),
                    'status_color' => $c->status->color(),
                    'counted_by'   => $c->countedBy->name,
                    'items_count'  => $c->items_count,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/InventoryCounts/Form', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'count_date'   => 'required|date',
            'notes'        => 'nullable|string',
        ]);

        $count = InventoryCount::create([
            'code'         => InventoryCount::generateCode(),
            'warehouse_id' => $data['warehouse_id'],
            'count_date'   => $data['count_date'],
            'status'       => InventoryCountStatus::Draft,
            'counted_by'   => auth()->id(),
            'notes'        => $data['notes'] ?? null,
        ]);

        $this->svc->populateItems($count);

        return redirect()->route('warehouse.inventory-counts.show', $count->id)
            ->with('success', "Phiếu kiểm kê {$count->code} đã được tạo. Vui lòng nhập số lượng thực đếm.");
    }

    public function show(InventoryCount $inventoryCount): Response
    {
        $inventoryCount->load(['warehouse', 'countedBy', 'items.product']);

        $items = $inventoryCount->items->map(fn ($item) => [
            'id'               => $item->id,
            'product_id'       => $item->product_id,
            'product_name'     => $item->product->name,
            'product_code'     => $item->product->code ?? '',
            'unit'             => $item->product->unit ?? '',
            'system_quantity'  => $item->system_quantity,
            'counted_quantity' => $item->counted_quantity,
            'difference'       => $item->counted_quantity !== null
                ? $item->counted_quantity - $item->system_quantity
                : null,
            'notes'            => $item->notes,
        ]);

        return Inertia::render('Warehouse/InventoryCounts/Show', [
            'count' => [
                'id'           => $inventoryCount->id,
                'code'         => $inventoryCount->code,
                'warehouse'    => $inventoryCount->warehouse->name,
                'count_date'   => $inventoryCount->count_date->format('Y-m-d'),
                'status'       => $inventoryCount->status->value,
                'status_label' => $inventoryCount->status->label(),
                'status_color' => $inventoryCount->status->color(),
                'counted_by'   => $inventoryCount->countedBy->name,
                'notes'        => $inventoryCount->notes,
            ],
            'items' => $items,
        ]);
    }

    public function saveItems(Request $request, InventoryCount $inventoryCount): RedirectResponse
    {
        $request->validate([
            'items'                    => 'required|array',
            'items.*.id'               => 'required|integer',
            'items.*.counted_quantity' => 'nullable|numeric|min:0',
            'items.*.notes'            => 'nullable|string|max:255',
        ]);

        try {
            $this->svc->saveItems($inventoryCount, $request->input('items'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã lưu số lượng thực đếm.');
    }

    public function confirm(Request $request, InventoryCount $inventoryCount): RedirectResponse
    {
        // Save latest counts from UI before confirming (atomic save+confirm)
        if ($request->has('items')) {
            $request->validate([
                'items'                    => 'array',
                'items.*.id'               => 'required|integer',
                'items.*.counted_quantity' => 'nullable|numeric|min:0',
                'items.*.notes'            => 'nullable|string|max:255',
            ]);
            try {
                $this->svc->saveItems($inventoryCount, $request->input('items'));
            } catch (\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        try {
            $this->svc->confirm($inventoryCount);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Phiếu kiểm kê {$inventoryCount->code} đã được xác nhận và điều chỉnh tồn kho.");
    }

    public function cancel(InventoryCount $inventoryCount): RedirectResponse
    {
        try {
            $this->svc->cancel($inventoryCount);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Phiếu kiểm kê {$inventoryCount->code} đã được hủy.");
    }

    public function destroy(InventoryCount $inventoryCount): RedirectResponse
    {
        if ($inventoryCount->status !== InventoryCountStatus::Cancelled) {
            return back()->with('error', 'Chỉ có thể xóa phiếu kiểm kê đã hủy.');
        }

        $inventoryCount->delete();

        return redirect()->route('warehouse.inventory-counts.index')
            ->with('success', 'Phiếu kiểm kê đã được xóa.');
    }
}
