<?php

namespace App\Http\Controllers;

use App\Models\AccountCode;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Số mã tối đa trả về cho danh sách tồn theo kho (form xuất kho). */

    private function q(Request $request): string
    {
        // Lowercase tại đây để mọi endpoint tìm kiếm dùng chung đều so khớp
        // không phân biệt hoa/thường trên PostgreSQL (LIKE của PG phân biệt case).
        // Chỉ xử lý case-insensitive — KHÔNG bỏ dấu tiếng Việt.
        return mb_strtolower(trim((string) $request->input('q', '')), 'UTF-8');
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
        // Chỉ NV còn hiệu lực lao động tại ngày chứng từ (mặc định hôm nay)
        $asOf = $request->input('date') ?: now()->toDateString();
        $items = Employee::query()
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                   ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', ["%{$q}%"])
            ))
            ->activeOn($asOf)
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
        $q         = $this->q($request);

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
     * Logic: WarehouseProductSearch — cùng nguồn tồn với backend xuất kho (AVCO/fallback + lô FIFO dự án).
     * Phân trang ?page=, meta.has_more.
     * GET /api/search/warehouse-products?warehouse_id=X&q=&project_id=
     */
    public function warehouseProducts(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $result = app(\App\Services\Inventory\WarehouseProductSearch::class)->search(
            $request->integer('warehouse_id'), $request->integer('project_id') ?: null,
            $this->q($request), $request->integer('page', 1)
        );

        return response()->json($result);
    }

    public function orders(Request $request): JsonResponse
    {
        $q     = $this->q($request);
        $limit = min((int) $request->input('limit', 20), 50);

        $items = Order::with(['customer', 'purchaseOrders.project'])
            ->when($q, fn ($b) => $b->where(fn ($b2) =>
                $b2->whereRaw('LOWER(orders.code) LIKE ?', ["%{$q}%"])
                   ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
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

    /**
     * Hợp đồng bán (HD-) — dùng cho liên kết contract_type=contract trên Báo cáo dòng tiền công ty.
     * Endpoint mới, chỉ 1 caller (ClassifyModal.vue) — gate đúng permission modal đó đã yêu cầu,
     * không mở rộng ra auth-only chung như các search endpoint cũ (phát hiện qua pre-deploy audit:
     * lộ tên khách hàng/hợp đồng cho mọi user đã login).
     */
    public function contracts(Request $request): JsonResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $q = $this->q($request);
        $items = Contract::query()
            ->with('customer')
            ->when($q, fn ($query) => $query->where(fn ($b) =>
                $b->whereRaw('LOWER(code) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', ["%{$q}%"])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]))
            ))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (Contract $c) => [
                'value' => $c->id,
                'label' => trim("{$c->code} — {$c->title}"),
                'code'  => $c->code,
                'meta'  => $c->customer?->name,
            ]);
        return response()->json(['data' => $items]);
    }

    /**
     * Người dùng hệ thống — dùng cho responsible_user_id (Báo cáo dòng tiền công ty).
     * Trả tên + email nhân viên -> chỉ user có quyền reconcile (đúng quyền modal classify
     * đã yêu cầu) mới enumerate được, không phải mọi user đã login (PII, phát hiện qua
     * pre-deploy audit).
     */
    public function users(Request $request): JsonResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $q = $this->q($request);
        $items = User::query()
            ->where('is_active', true)
            ->when($q, fn ($query) => $query->where(fn ($b) =>
                $b->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$q}%"])
            ))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'value' => $u->id,
                'label' => $u->name,
                'meta'  => $u->email,
            ]);
        return response()->json(['data' => $items]);
    }

    /**
     * Hợp đồng mua (HD-MH-) — dùng chung bởi 2 module độc lập: Purchasing/SupplierAdvances/Form.vue
     * (tạo tạm ứng NCC, cần `purchasing.view`) và ClassifyModal.vue của Báo cáo dòng tiền công ty
     * (cần `reports.bank_cashflow.reconcile`). Gate rộng hơn 2 endpoint trên vì route dùng chung —
     * chỉ `purchasing.view` sẽ phá luồng tạm ứng NCC hiện có (phát hiện qua pre-deploy audit).
     */
    public function purchaseContracts(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->canAny(['purchasing.view', 'reports.bank_cashflow.reconcile']),
            403
        );

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
