<?php

namespace App\Http\Controllers;

use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private function q(Request $request): string
    {
        return trim($request->input('q', ''));
    }

    public function suppliers(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Supplier::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(tax_code, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'phone'])
            ->map(fn ($s) => [
                'value' => $s->id,
                'label' => $s->name,
                'code'  => $s->code,
                'meta'  => $s->phone,
            ]);
        return response()->json(['data' => $items]);
    }

    public function customers(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Customer::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(tax_code, \'\')) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'phone', 'is_fdi'])
            ->map(fn ($c) => [
                'value'  => $c->id,
                'label'  => $c->name,
                'code'   => $c->code,
                'meta'   => $c->phone,
                'is_fdi' => (bool) $c->is_fdi,
            ]);
        return response()->json(['data' => $items]);
    }

    public function products(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Product::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'unit', 'cost_price', 'sell_price', 'vat_percent'])
            ->map(fn ($p) => [
                'value'      => $p->id,
                'label'      => $p->name,
                'code'       => $p->code,
                'meta'       => $p->unit,
                'cost_price' => (float) $p->cost_price,
                'sell_price' => (float) $p->sell_price,
                'vat_percent'=> (float) ($p->vat_percent ?? 0),
                'unit'       => $p->unit,
            ]);
        return response()->json(['data' => $items]);
    }

    public function services(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Service::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'unit', 'price'])
            ->map(fn ($s) => [
                'value' => $s->id,
                'label' => $s->name,
                'code'  => $s->code,
                'meta'  => $s->unit,
                'price' => (float) $s->price,
                'unit'  => $s->unit,
            ]);
        return response()->json(['data' => $items]);
    }

    public function accountCodes(Request $request): JsonResponse
    {
        $q         = $this->q($request);
        $detailOnly = $request->boolean('detail_only', false);

        $items = AccountCode::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('CAST(id AS TEXT) LIKE ?', ["%{$q}%"])
            ))
            ->when($detailOnly, fn ($b) => $b->where('is_detail', true))
            ->orderBy('id')
            ->limit(40)
            ->get(['id', 'name', 'is_detail'])
            ->map(fn ($a) => [
                'value' => $a->id,
                'label' => $a->name,
                'code'  => (string) $a->id,
                'meta'  => $a->is_detail ? null : 'Tổng hợp',
            ]);
        return response()->json(['data' => $items]);
    }

    public function employees(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Employee::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'department'])
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => $e->name,
                'code'  => $e->code,
                'meta'  => $e->department,
            ]);
        return response()->json(['data' => $items]);
    }

    public function projects(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Project::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
            ))
            ->whereIn('status', ['planning', 'in_progress', 'on_hold'])
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'status'])
            ->map(fn ($p) => [
                'value' => $p->id,
                'label' => $p->name,
                'code'  => $p->code,
            ]);
        return response()->json(['data' => $items]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $q = $this->q($request);
        $items = Warehouse::query()
            ->when($q, fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name'])
            ->map(fn ($w) => [
                'value' => $w->id,
                'label' => $w->name,
            ]);
        return response()->json(['data' => $items]);
    }

    /**
     * Trả sản phẩm có tồn kho trong kho cụ thể.
     * - Non-project: lấy từ inventory_balances (AVCO).
     * - Project: lấy từ project_inventory_lots (FIFO).
     * GET /api/search/warehouse-products?warehouse_id=X&q=&project_id=
     */
    public function warehouseProducts(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $warehouseId = $request->integer('warehouse_id');
        $projectId   = $request->integer('project_id');
        $keyword     = $this->q($request);

        if ($projectId) {
            $lots = ProjectInventoryLot::with('product')
                ->where('project_id', $projectId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'active')
                ->whereRaw('issued_qty < received_qty')
                ->get()
                ->groupBy('product_id');

            $items = $lots->map(function ($productLots) use ($keyword) {
                $first   = $productLots->first();
                $product = $first->product;
                if (! $product || ! $product->is_active) return null;

                if ($keyword) {
                    $kw = mb_strtolower($keyword);
                    if (! str_contains(mb_strtolower($product->name), $kw)
                        && ! str_contains(mb_strtolower($product->code ?? ''), $kw)) {
                        return null;
                    }
                }

                $availableQty = round(
                    $productLots->sum(fn ($l) => (float) $l->received_qty - (float) $l->issued_qty),
                    3
                );

                return [
                    'value'      => $product->id,
                    'label'      => $product->name,
                    'code'       => $product->code,
                    'meta'       => $product->unit ? "Tồn: {$availableQty} {$product->unit}" : "Tồn: {$availableQty}",
                    'unit'       => $product->unit,
                    'qty'        => $availableQty,
                    'avg_cost'   => null,
                    'sell_price' => (float) ($product->sell_price ?? 0),
                ];
            })->filter()->values()->take(30);

            return response()->json(['data' => $items]);
        }

        // Non-project: query từ inventory_balances (AVCO)
        $items = InventoryBalance::with('product')
            ->where('warehouse_id', $warehouseId)
            ->where('qty_on_hand', '>', 0)
            ->whereHas('product', function ($q) use ($keyword) {
                $q->where('is_active', true);
                if ($keyword) {
                    $q->where(function ($b) use ($keyword) {
                        $b->whereRaw('LOWER(name) LIKE ?', ["%{$keyword}%"])
                          ->orWhereRaw('LOWER(code) LIKE ?', ["%{$keyword}%"]);
                    });
                }
            })
            ->limit(30)
            ->get()
            ->map(fn ($ib) => [
                'value'      => $ib->product_id,
                'label'      => $ib->product->name,
                'code'       => $ib->product->code,
                'meta'       => $ib->product->unit
                                    ? "Tồn: {$ib->qty_on_hand} {$ib->product->unit}"
                                    : "Tồn: {$ib->qty_on_hand}",
                'unit'       => $ib->product->unit,
                'qty'        => (float) $ib->qty_on_hand,
                'avg_cost'   => (float) $ib->avg_cost,
                'sell_price' => (float) ($ib->product->sell_price ?? 0),
            ]);

        return response()->json(['data' => $items]);
    }
}
