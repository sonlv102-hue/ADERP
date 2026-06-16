<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectInventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $projectId   = $request->integer('project_id') ?: null;
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $status      = $request->input('status');

        $query = ProjectInventoryLot::with(['product', 'warehouse', 'project', 'purchaseOrder', 'stockEntry'])
            ->when($projectId,   fn ($q) => $q->where('project_id', $projectId))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($status,      fn ($q) => $q->where('status', $status))
            ->orderBy('project_id')
            ->orderBy('received_at');

        $lots = $query->paginate(50)->withQueryString()->through(fn ($l) => [
            'id'                   => $l->id,
            'project_code'         => $l->project?->code,
            'project_name'         => $l->project?->name,
            'warehouse_name'       => $l->warehouse?->name,
            'product_code'         => $l->product?->code,
            'product_name'         => $l->product?->name,
            'unit'                 => $l->product?->unit,
            'inventory_account'    => $l->product?->inventory_account,
            'purchase_order_code'  => $l->purchaseOrder?->code,
            'stock_entry_code'     => $l->stockEntry?->code,
            'received_qty'         => (float) $l->received_qty,
            'issued_qty'           => (float) $l->issued_qty,
            'available_qty'        => (float) $l->received_qty - (float) $l->issued_qty,
            'unit_cost'            => (float) $l->unit_cost,
            'total_cost'           => (float) $l->unit_cost * ((float) $l->received_qty - (float) $l->issued_qty),
            'received_at'          => $l->received_at?->format('d/m/Y'),
            'status'               => $l->status,
        ]);

        return Inertia::render('Warehouse/ProjectInventory/Index', [
            'lots'       => $lots,
            'projects'   => Project::whereNotIn('status', ['completed', 'cancelled'])
                ->orderByDesc('id')
                ->get(['id', 'code', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name]),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'filters'    => [
                'project_id'   => $projectId,
                'warehouse_id' => $warehouseId,
                'status'       => $status,
            ],
        ]);
    }
}
