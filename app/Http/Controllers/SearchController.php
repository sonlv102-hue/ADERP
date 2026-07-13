<?php

namespace App\Http\Controllers;

use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\Service;
use App\Models\StockEntryItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    private function q(Request $request): string
    {
        return trim($request->input('q', ''));
    }

    public function suppliers(Request $request): JsonResponse
    {
        $q     = $this->q($request);
        $limit = min((int) $request->input('limit', 20), 50);

        $items = Supplier::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(tax_code, \'\')) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'tax_code', 'phone', 'email', 'payable_account_code'])
            ->map(fn ($s) => [
                'value'                => $s->id,
                'label'                => $s->name,
                'code'                 => $s->code,
                'meta'                 => collect([$s->tax_code, $s->phone])->filter()->implode(' · '),
                'tax_code'             => $s->tax_code,
                'phone'                => $s->phone,
                'email'                => $s->email,
                'payable_account_code' => $s->payable_account_code ?? '3311',
            ]);
        return response()->json(['data' => $items]);
    }

    public function customers(Request $request): JsonResponse
    {
        $q     = $this->q($request);
        $limit = min((int) $request->input('limit', 20), 50);

        $items = Customer::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(tax_code, \'\')) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'tax_code', 'phone', 'email', 'address', 'is_fdi', 'receivable_account_code'])
            ->map(fn ($c) => [
                'value'                    => $c->id,
                'label'                    => $c->name,
                'code'                     => $c->code,
                'meta'                     => collect([$c->tax_code, $c->phone])->filter()->implode(' · '),
                'tax_code'                 => $c->tax_code,
                'phone'                    => $c->phone,
                'email'                    => $c->email,
                'address'                  => $c->address,
                'is_fdi'                   => (bool) $c->is_fdi,
                'receivable_account_code'  => $c->receivable_account_code ?? '1311',
            ]);
        return response()->json(['data' => $items]);
    }

    public function products(Request $request): JsonResponse
    {
        $q     = $this->q($request);
        $limit = min((int) $request->input('limit', 20), 50);

        $items = Product::with('category')
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(products.name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(products.code) LIKE ?', ["%{$q}%"])
                   ->orWhereHas('category', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ))
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'unit', 'cost_price', 'sell_price', 'vat_percent',
                   'revenue_account_code', 'inventory_account', 'category_id'])
            ->map(fn ($p) => [
                'value'                  => $p->id,
                'label'                  => $p->name,
                'code'                   => $p->code,
                'meta'                   => collect([$p->unit, $p->category?->name])->filter()->implode(' · '),
                'cost_price'             => (float) $p->cost_price,
                'sell_price'             => (float) $p->sell_price,
                'vat_percent'            => (float) ($p->vat_percent ?? 0),
                'unit'                   => $p->unit,
                'category_name'          => $p->category?->name,
                'revenue_account_code'   => $p->revenue_account_code,
                'inventory_account'      => $p->inventory_account,
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
                   ->orWhereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
            ))
            ->when($detailOnly, fn ($b) => $b->where('is_detail', true))
            ->orderBy('code')
            ->limit(40)
            ->get(['code', 'name', 'is_detail'])
            ->map(fn ($a) => [
                'value' => $a->code,
                'label' => $a->name,
                'code'  => $a->code,
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
     * Trả danh sách Purchase Orders có ít nhất 1 dòng gắn với project_id.
     * GET /api/search/project-purchase-orders?project_id=X&q=
     */
    public function projectPurchaseOrders(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $projectId = $request->integer('project_id');
        $q         = mb_strtolower($this->q($request));

        $items = PurchaseOrder::query()
            ->whereHas('items', fn ($b) => $b->where('project_id', $projectId))
            ->with(['supplier', 'items' => fn ($b) => $b->where('project_id', $projectId)])
            ->whereIn('status', ['approved', 'partially_received', 'received', 'completed'])
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(purchase_orders.code) LIKE ?', ["%{$q}%"])
                   ->orWhereHas('supplier', fn ($s) => $s->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($po) => [
                'value'         => $po->id,
                'label'         => $po->code,
                'code'          => $po->code,
                'meta'          => ($po->supplier?->name ?? '') . ' · ' . ($po->order_date?->format('d/m/Y') ?? ''),
                'supplier_name' => $po->supplier?->name ?? '',
                'order_date'    => $po->order_date?->format('d/m/Y'),
                'item_count'    => $po->items->count(),
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
            // Lấy tồn từ project_inventory_lots (FIFO) của dự án
            $lots = ProjectInventoryLot::with('product')
                ->where('project_id', $projectId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'active')
                ->whereRaw('issued_qty < received_qty')
                ->get()
                ->groupBy('product_id');

            // Lấy tồn từ inventory_balances (AVCO) của kho chung
            $avcoBalances = \App\Models\InventoryBalance::where('warehouse_id', $warehouseId)
                ->where('qty_on_hand', '>', 0)
                ->get()
                ->keyBy('product_id');

            // Gộp tất cả product_id có tồn
            $allProductIds = collect($lots->keys())->merge($avcoBalances->keys())->unique();

            $items = $allProductIds->map(function ($productId) use ($lots, $avcoBalances, $keyword) {
                $product = null;
                if ($lots->has($productId)) {
                    $product = $lots[$productId]->first()->product;
                } elseif ($avcoBalances->has($productId)) {
                    $product = $avcoBalances[$productId]->product;
                }
                if (! $product || ! $product->is_active) return null;

                if ($keyword) {
                    $kw = mb_strtolower($keyword);
                    if (! str_contains(mb_strtolower($product->name), $kw)
                        && ! str_contains(mb_strtolower($product->code ?? ''), $kw)) {
                        return null;
                    }
                }

                // Tồn từ project lots (FIFO)
                $lotQty = 0.0;
                $lotCostValue = 0.0;
                if ($lots->has($productId)) {
                    foreach ($lots[$productId] as $l) {
                        $avail = (float) $l->received_qty - (float) $l->issued_qty;
                        $lotQty += $avail;
                        $lotCostValue += $avail * (float) ($l->unit_cost ?? 0);
                    }
                }

                // Tồn từ AVCO (kho chung)
                $avcoQty  = $avcoBalances->has($productId) ? (float) $avcoBalances[$productId]->qty_on_hand : 0.0;
                $avcoRate = $avcoBalances->has($productId) ? (float) $avcoBalances[$productId]->avg_cost : 0.0;

                $totalQty = round($lotQty + $avcoQty, 3);
                if ($totalQty <= 0) return null;

                // Giá bình quân tổng hợp
                $totalCostValue = $lotCostValue + ($avcoQty * $avcoRate);
                $avgCost = $totalQty > 0 ? round($totalCostValue / $totalQty, 2) : null;

                // Chú thích nguồn tồn
                $sourceParts = [];
                if ($lotQty > 0) $sourceParts[] = "lô DA: {$lotQty}";
                if ($avcoQty > 0) $sourceParts[] = "kho chung: {$avcoQty}";
                $meta = "Tồn: {$totalQty}" . ($product->unit ? " {$product->unit}" : '');
                if (count($sourceParts) > 1) {
                    $meta .= ' (' . implode(', ', $sourceParts) . ')';
                }

                return [
                    'value'      => $product->id,
                    'label'      => $product->name,
                    'code'       => $product->code,
                    'meta'       => $meta,
                    'unit'       => $product->unit,
                    'qty'        => $totalQty,
                    'avg_cost'   => $avgCost,
                    'sell_price' => (float) ($product->sell_price ?? 0),
                    'has_serial' => (bool) ($product->has_serial ?? false),
                ];
            })->filter()->values()->take(30);

            return response()->json(['data' => $items]);
        }

        // Non-project: query từ inventory_balances (AVCO)
        $balanceRows = InventoryBalance::with('product')
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
            ->get();

        if ($balanceRows->isNotEmpty()) {
            $items = $balanceRows->map(fn ($ib) => [
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
                'has_serial' => (bool) ($ib->product->has_serial ?? false),
            ]);
            return response()->json(['data' => $items]);
        }

        // Fallback: inventory_balances chưa khởi tạo → tính từ stock_movements (active only)
        // Chỉ áp dụng cho sản phẩm chưa có AVCO record. Sản phẩm đã có record (dù qty=0) không
        // được fallback — tránh hiển thị tồn sai khi AVCO đã ghi nhận stock = 0.
        $productsWithAvco = InventoryBalance::where('warehouse_id', $warehouseId)
            ->pluck('product_id')
            ->toArray();

        $movQty = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('project_id')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->when(! empty($productsWithAvco), fn ($q) => $q->whereNotIn('product_id', $productsWithAvco))
            ->select('product_id', DB::raw('SUM(quantity) AS qty'))
            ->groupBy('product_id')
            ->having(DB::raw('SUM(quantity)'), '>', 0)
            ->get()
            ->keyBy('product_id');

        if ($movQty->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $entryAvg = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
            ->where('stock_entries.warehouse_id', $warehouseId)
            ->whereIn('stock_entry_items.product_id', $movQty->keys()->toArray())
            ->where('stock_entries.status', 'confirmed')
            ->select('stock_entry_items.product_id')
            ->selectRaw(
                'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost'
            )
            ->groupBy('stock_entry_items.product_id')
            ->get()
            ->keyBy('product_id');

        $products = Product::whereIn('id', $movQty->keys()->toArray())
            ->where('is_active', true)
            ->when($keyword, function ($q, $kw) {
                $q->where(function ($b) use ($kw) {
                    $b->whereRaw('LOWER(name) LIKE ?', ["%{$kw}%"])
                      ->orWhereRaw('LOWER(code) LIKE ?', ["%{$kw}%"]);
                });
            })
            ->limit(30)
            ->get();

        $items = $products->map(function ($p) use ($movQty, $entryAvg) {
            $qty     = (float) ($movQty[$p->id]->qty ?? 0);
            $avgCost = (float) ($entryAvg[$p->id]->avg_cost ?? 0);
            return [
                'value'      => $p->id,
                'label'      => $p->name,
                'code'       => $p->code,
                'meta'       => $p->unit ? "Tồn: {$qty} {$p->unit}" : "Tồn: {$qty}",
                'unit'       => $p->unit,
                'qty'        => $qty,
                'avg_cost'   => round($avgCost, 2),
                'sell_price' => (float) ($p->sell_price ?? 0),
                'has_serial' => (bool) ($p->has_serial ?? false),
            ];
        })->values();

        return response()->json(['data' => $items]);
    }

    public function orders(Request $request): JsonResponse
    {
        $q     = $this->q($request);
        $limit = min((int) $request->input('limit', 20), 50);

        $items = Order::with(['customer', 'purchaseOrders.project'])
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(orders.code) LIKE ?', ['%' . strtolower($q) . '%'])
                   ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($q) . '%']))
            ))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'code', 'customer_id', 'order_date'])
            ->map(function ($o) {
                $projectPo = $o->purchaseOrders->first(fn ($po) => $po->project_id !== null);
                return [
                    'value'        => $o->id,
                    'label'        => $o->customer?->name ?? $o->code,
                    'code'         => $o->code,
                    'meta'         => collect([
                        $o->order_date?->format('d/m/Y'),
                        $projectPo?->project?->code,
                    ])->filter()->implode(' · '),
                    'customer_id'  => $o->customer_id,
                    'customer_name'=> $o->customer?->name,
                    'project_id'   => $projectPo?->project_id,
                    'project_code' => $projectPo?->project?->code,
                    'project_name' => $projectPo?->project?->name,
                ];
            });

        return response()->json(['data' => $items]);
    }

    public function purchaseContracts(Request $request): JsonResponse
    {
        $q          = $this->q($request);
        $supplierId = $request->input('supplier_id');

        $items = \App\Models\PurchaseContract::query()
            ->with('purchaseOrder')
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($q, fn ($query) => $query->where(fn ($b) => 
                $b->whereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                  ->orWhereRaw('LOWER(title) LIKE ?', ["%{$q}%"])
            ))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($c) => [
                'value'               => $c->id,
                'label'               => "{$c->code} — {$c->title}",
                'code'                => $c->code,
                'purchase_order_id'   => $c->purchase_order_id,
                'purchase_order_code' => $c->purchaseOrder?->code,
            ]);

        return response()->json(['data' => $items]);
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $q                  = $this->q($request);
        $supplierId         = $request->input('supplier_id');
        $purchaseContractId = $request->input('purchase_contract_id');

        $contractPoId = null;
        if ($purchaseContractId) {
            $contract = \App\Models\PurchaseContract::find($purchaseContractId);
            if ($contract && $contract->purchase_order_id) {
                $contractPoId = $contract->purchase_order_id;
            }
        }

        $items = \App\Models\PurchaseOrder::query()
            ->when($contractPoId, fn ($query) => $query->where('id', $contractPoId))
            ->when(!$contractPoId && $supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($q, fn ($query) => $query->whereRaw('LOWER(code) LIKE ?', ["%{$q}%"]))
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'code'])
            ->map(function ($po) {
                $contract = \App\Models\PurchaseContract::where('purchase_order_id', $po->id)->first();
                return [
                    'value'                  => $po->id,
                    'label'                  => $po->code,
                    'code'                   => $po->code,
                    'purchase_contract_id'   => $contract?->id,
                    'purchase_contract_code' => $contract?->code,
                    'purchase_contract_title'=> $contract?->title,
                ];
            });

        return response()->json(['data' => $items]);
    }
}
