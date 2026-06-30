<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CustomsStatus;
use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\InventoryBalance;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $q      = $request->input('q');
        $status = $request->input('status');

        return Inertia::render('Sales/Orders/Index', [
            'orders' => Order::with(['customer', 'creator', 'quotation'])
                ->withCount('items')
                ->addSelect([
                    'items_total' => OrderItem::selectRaw('COALESCE(SUM((quantity * unit_price - COALESCE(discount_amount, 0)) + ROUND((quantity * unit_price - COALESCE(discount_amount, 0)) * COALESCE(vat_rate, 0) / 100)), 0)')
                        ->whereColumn('order_id', 'orders.id'),
                    'has_contract' => \App\Models\Contract::selectRaw('COUNT(*)')
                        ->whereColumn('order_id', 'orders.id')
                        ->limit(1),
                    'undelivered_qty' => OrderItem::selectRaw('COALESCE(SUM(GREATEST(0, quantity - COALESCE(delivered_quantity, 0))), 0)')
                        ->whereColumn('order_id', 'orders.id')
                        ->whereNotNull('product_id'),
                ])
                ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                    $sq->where('code', 'ilike', "%{$q}%")
                       ->orWhere('notes', 'ilike', "%{$q}%")
                       ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%")
                                                              ->orWhere('code', 'ilike', "%{$q}%"))
                       ->orWhereHas('creator', fn ($u) => $u->where('name', 'ilike', "%{$q}%"))
                       ->orWhereHas('quotation', fn ($qt) => $qt->where('code', 'ilike', "%{$q}%"));
                }))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($o) => [
                    'id'               => $o->id,
                    'code'             => $o->code,
                    'customer'         => $o->customer->name,
                    'order_date'       => $o->order_date->format('d/m/Y'),
                    'expected_delivery'=> $o->expected_delivery?->format('d/m/Y'),
                    'status'           => $o->status->value,
                    'status_label'     => $o->status->label(),
                    'status_color'     => $o->status->color(),
                    'creator'          => $o->creator->name,
                    'quotation_code'   => $o->quotation?->code,
                    'items_count'      => $o->items_count,
                    'total'            => (float) $o->items_total,
                    'has_contract'     => (int) $o->has_contract > 0,
                    'delivery_status'  => $this->resolveDeliveryStatus($o),
                    'customs_status'       => $o->customs_status->value,
                    'customs_status_label' => $o->customs_status->label(),
                    'customs_status_color' => $o->customs_status->color(),
                ]),
            'filters'  => ['q' => $q, 'status' => $status],
            'statuses' => collect(OrderStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(Request $request): Response
    {
        $supplementaryFor = null;
        if ($request->query('supplementary_for')) {
            $orig = Order::with('customer')->find($request->query('supplementary_for'));
            if ($orig) {
                $supplementaryFor = [
                    'id'            => $orig->id,
                    'code'          => $orig->code,
                    'customer_id'   => $orig->customer_id,
                    'customer_name' => $orig->customer->name,
                ];
            }
        }

        return Inertia::render('Sales/Orders/Form', [
            'nextCode'         => Order::generateCode(),
            'quotations'       => $this->approvedQuotations(),
            'fromQuotationId'  => $request->query('from_quotation'),
            'supplementaryFor' => $supplementaryFor,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                        => ['required', 'string', 'unique:orders,code'],
            'customer_id'                 => ['required', 'exists:customers,id'],
            'quotation_id'                => ['nullable', 'exists:quotations,id'],
            'supplementary_for_order_id'  => ['nullable', 'exists:orders,id'],
            'order_date'                  => ['required', 'date'],
            'expected_delivery'           => ['nullable', 'date'],
            'notes'                       => ['nullable', 'string'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.product_id'          => ['nullable', 'exists:products,id'],
            'items.*.service_id'          => ['nullable', 'exists:services,id'],
            'items.*.name'                => ['required', 'string'],
            'items.*.unit'                => ['nullable', 'string'],
            'items.*.quantity'            => ['required', 'integer', 'min:1'],
            'items.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percent'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $isFdi = Customer::where('id', $data['customer_id'])->value('is_fdi');

        $order = Order::create([
            'code'                        => $data['code'],
            'customer_id'                 => $data['customer_id'],
            'quotation_id'                => $data['quotation_id'] ?? null,
            'supplementary_for_order_id'  => $data['supplementary_for_order_id'] ?? null,
            'order_date'                  => $data['order_date'],
            'expected_delivery'           => $data['expected_delivery'] ?? null,
            'notes'                       => $data['notes'] ?? null,
            'created_by'                  => auth()->id(),
            'status'                      => OrderStatus::Pending,
            'customs_status'              => $isFdi ? CustomsStatus::Pending : CustomsStatus::NotRequired,
        ]);

        $productPrices = Product::with('category:id,revenue_account_code')
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->unique())
            ->get(['id', 'cost_price', 'vat_percent', 'item_type', 'revenue_account_code', 'category_id'])
            ->keyBy('id');

        foreach ($data['items'] as $item) {
            if (!empty($item['product_id']) && isset($productPrices[$item['product_id']])) {
                $prod = $productPrices[$item['product_id']];
                $vatDiv = 1 + (float)($prod->vat_percent ?? 0) / 100;
                $item['unit_cogs']             = round((float)$prod->cost_price / $vatDiv, 2);
                $item['unit_cogs_source']      = 'snapshot';
                $item['revenue_account_code']  = $this->resolveRevenueAccount(
                    $prod->revenue_account_code,
                    $prod->category?->revenue_account_code,
                    $prod->item_type,
                    false
                );
            } elseif (!empty($item['service_id'])) {
                $item['revenue_account_code']  = '5113';
            }
            $order->items()->create($item);
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã tạo đơn hàng.');
    }

    public function show(Order $order): Response
    {
        $order->load(['customer', 'creator', 'quotation', 'items.product', 'items.service', 'contracts', 'attachments.creator', 'purchaseOrders.supplier']);

        // Tồn kho hiện tại cho từng sản phẩm trong đơn
        $productIds = $order->items->whereNotNull('product_id')->pluck('product_id');
        $stocks = InventoryBalance::stockForProducts($productIds);

        // Tồn kho theo từng kho từ AVCO (inventory_balances)
        $avcoByWarehouse = InventoryBalance::whereIn('product_id', $productIds)
            ->with('warehouse:id,name')
            ->get(['product_id', 'warehouse_id', 'qty_on_hand'])
            ->groupBy('product_id')
            ->map(fn ($bs) => $bs
                ->filter(fn ($b) => (float) $b->qty_on_hand > 0)
                ->map(fn ($b) => [
                    'warehouse_id'   => $b->warehouse_id,
                    'warehouse_name' => $b->warehouse?->name ?? "Kho #{$b->warehouse_id}",
                    'qty'            => (float) $b->qty_on_hand,
                ])->values()
            );

        // Fallback: products không có inventory_balances → tính từ stock_movements
        $productsWithBalance = $avcoByWarehouse->keys()->values()->toArray();
        $productsNeedFallback = $productIds->diff($productsWithBalance)->values();
        $movementByWarehouse = collect();
        if ($productsNeedFallback->isNotEmpty()) {
            $movementByWarehouse = StockMovement::active()
                ->whereIn('product_id', $productsNeedFallback)
                ->selectRaw('product_id, warehouse_id, SUM(quantity) as qty')
                ->groupBy('product_id', 'warehouse_id')
                ->havingRaw('SUM(quantity) > 0')
                ->with('warehouse:id,name')
                ->get()
                ->groupBy('product_id')
                ->map(fn ($group) => $group->map(fn ($m) => [
                    'warehouse_id'   => $m->warehouse_id,
                    'warehouse_name' => $m->warehouse?->name ?? "Kho #{$m->warehouse_id}",
                    'qty'            => (float) $m->qty,
                ])->values());
        }
        $stocksPerWarehouse = $avcoByWarehouse->union($movementByWarehouse);

        // Confirmed exit qty per order_item_id (live, authoritative)
        $orderItemIds = $order->items->pluck('id');
        $confirmedQtyByOrderItemId = $orderItemIds->isNotEmpty()
            ? StockExitItem::select('stock_exit_items.order_item_id', DB::raw('SUM(stock_exit_items.quantity) as qty'))
                ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
                ->whereIn('stock_exit_items.order_item_id', $orderItemIds)
                ->where('stock_exits.status', 'confirmed')
                ->groupBy('stock_exit_items.order_item_id')
                ->pluck('qty', 'order_item_id')
                ->map(fn($v) => (float) $v)
            : collect();

        // Pending exit qty per order_item_id (draft) — fallback to product_id if no link
        $pendingQtyByOrderItemId = $orderItemIds->isNotEmpty()
            ? StockExitItem::select('stock_exit_items.order_item_id', DB::raw('SUM(stock_exit_items.quantity) as qty'))
                ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
                ->whereIn('stock_exit_items.order_item_id', $orderItemIds)
                ->where('stock_exits.status', 'draft')
                ->groupBy('stock_exit_items.order_item_id')
                ->pluck('qty', 'order_item_id')
                ->map(fn($v) => (float) $v)
            : collect();

        // Fallback: phiếu xuất nháp theo product_id (cho exit chưa có order_item_id)
        $draftExitQtyByProduct = $productIds->isNotEmpty()
            ? StockExitItem::whereHas(
                'stockExit',
                fn($q) => $q->where('order_id', $order->id)->where('status', 'draft')
            )->whereIn('product_id', $productIds)
             ->selectRaw('product_id, SUM(quantity) as qty')
             ->groupBy('product_id')
             ->pluck('qty', 'product_id')
             ->map(fn($v) => (float) $v)
            : collect();

        return Inertia::render('Sales/Orders/Show', [
            'order' => [
                'id'                => $order->id,
                'code'              => $order->code,
                'customer'          => ['id' => $order->customer->id, 'name' => $order->customer->name, 'is_fdi' => (bool) $order->customer->is_fdi],
                'quotation'         => $order->quotation ? ['id' => $order->quotation->id, 'code' => $order->quotation->code] : null,
                'order_date'           => $order->order_date->format('d/m/Y'),
                'expected_delivery'    => $order->expected_delivery?->format('d/m/Y'),
                'expected_delivery_raw'=> $order->expected_delivery?->format('Y-m-d'),
                'status'            => $order->status->value,
                'status_label'      => $order->status->label(),
                'status_color'      => $order->status->color(),
                'notes'             => $order->notes,
                'project_id'        => $order->project_id,
                'creator'           => $order->creator->name,
                'created_at'        => $order->created_at->format('d/m/Y'),
                'total'             => $order->total(),
                'items'             => $order->items->map(fn ($item) => [
                    'id'                 => $item->id,
                    'product_id'         => $item->product_id,
                    'name'               => $item->name,
                    'unit'               => $item->unit,
                    'quantity'           => $item->quantity,
                    'delivered_quantity'  => $item->delivered_quantity,
                    'confirmed_exit_qty' => (float) ($confirmedQtyByOrderItemId[$item->id] ?? $item->delivered_quantity),
                    'remaining'          => max(0, (float)$item->quantity - max((float)$item->delivered_quantity, (float)($confirmedQtyByOrderItemId[$item->id] ?? 0))),
                    'current_stock'      => (float) ($stocks[$item->product_id] ?? 0),
                    'stock_by_warehouse' => $item->product_id
                        ? $stocksPerWarehouse->get($item->product_id, collect())->toArray()
                        : [],
                    'pending_exit_qty'   => (float) ($pendingQtyByOrderItemId[$item->id] ?? $draftExitQtyByProduct[$item->product_id] ?? 0),
                    'unit_price'         => $item->unit_price,
                    'vat_rate'           => $item->vat_rate !== null ? (float) $item->vat_rate : null,
                    'discount_percent'   => (float) $item->discount_percent,
                    'discount_amount'    => (int) $item->discount_amount,
                    'line_total'         => $item->lineTotal(),
                    'vat_amount'         => round($item->lineTotal() * (float)($item->vat_rate ?? 0) / 100),
                ]),
                'contracts' => $order->contracts->map(fn ($c) => [
                    'id'   => $c->id,
                    'code' => $c->code,
                ]),
                'attachments' => $order->attachments->map(fn ($a) => [
                    'id'        => $a->id,
                    'file_name' => $a->file_name,
                    'file_url'  => Storage::disk('public')->url($a->file_path),
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                    'created_by'=> $a->creator->name,
                ]),
                'customs_status'        => $order->customs_status->value,
                'customs_status_label'  => $order->customs_status->label(),
                'customs_status_color'  => $order->customs_status->color(),
                'customs_declared_at'     => $order->customs_declared_at?->format('d/m/Y H:i'),
                'customs_declared_at_raw' => $order->customs_declared_at?->format('Y-m-d'),
                'customs_document_name' => $order->customs_document_name,
                'customs_document_url'  => $order->customs_document_path ? Storage::disk('public')->url($order->customs_document_path) : null,
                'customs_notes'         => $order->customs_notes,
                'purchase_orders'       => $order->purchaseOrders->map(fn ($po) => [
                    'id'            => $po->id,
                    'code'          => $po->code,
                    'supplier'      => $po->supplier->name,
                    'order_date'    => $po->order_date->format('d/m/Y'),
                    'status'        => $po->status->value,
                    'status_label'  => $po->status->label(),
                    'status_color'  => $po->status->color(),
                    'total'         => (float) $po->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_price')),
                ]),
            ],
        ]);
    }

    public function edit(Order $order): Response
    {
        $isAdmin = auth()->user()->hasRole('admin');
        abort_if(
            $order->status === OrderStatus::Cancelled,
            403,
            'Không thể sửa đơn hàng đã hủy.'
        );
        abort_if(
            !$isAdmin && $order->status === OrderStatus::Completed,
            403,
            'Không thể sửa đơn hàng đã hoàn thành.'
        );

        $order->load(['items', 'customer']);

        return Inertia::render('Sales/Orders/Form', [
            'order'      => [
                'id'                => $order->id,
                'code'              => $order->code,
                'customer_id'       => $order->customer_id,
                'quotation_id'      => $order->quotation_id,
                'order_date'        => $order->order_date->format('Y-m-d'),
                'expected_delivery' => $order->expected_delivery?->format('Y-m-d'),
                'notes'             => $order->notes,
                'items'             => $order->items->map(fn ($item) => [
                    'product_id'       => $item->product_id,
                    'service_id'       => $item->service_id,
                    'name'             => $item->name,
                    'unit'             => $item->unit,
                    'quantity'         => $item->quantity,
                    'unit_price'       => $item->unit_price,
                    'vat_rate'         => $item->vat_rate !== null ? (float) $item->vat_rate : null,
                    'discount_percent' => (float) $item->discount_percent,
                    'discount_amount'  => (int) $item->discount_amount,
                    '_type'            => $item->product_id ? 'product' : 'service',
                ]),
            ],
            'initialCustomerName' => $order->customer?->name ?? '',
            'initialCustomerCode' => $order->customer?->code ?? '',
            'initialCustomerFdi'  => (bool) ($order->customer?->is_fdi ?? false),
            'quotations'          => $this->approvedQuotations(),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        abort_if(
            in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled]),
            403
        );

        $data = $request->validate([
            'customer_id'         => ['required', 'exists:customers,id'],
            'order_date'          => ['required', 'date'],
            'expected_delivery'   => ['nullable', 'date'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['nullable', 'exists:products,id'],
            'items.*.service_id'  => ['nullable', 'exists:services,id'],
            'items.*.name'        => ['required', 'string'],
            'items.*.unit'        => ['nullable', 'string'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'       => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $order->update([
            'customer_id'       => $data['customer_id'],
            'order_date'        => $data['order_date'],
            'expected_delivery' => $data['expected_delivery'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);

        $productPrices = Product::with('category:id,revenue_account_code')
            ->whereIn('id', collect($data['items'])->pluck('product_id')->filter()->unique())
            ->get(['id', 'cost_price', 'vat_percent', 'item_type', 'revenue_account_code', 'category_id'])
            ->keyBy('id');

        $order->items()->delete();
        foreach ($data['items'] as $item) {
            if (!empty($item['product_id']) && isset($productPrices[$item['product_id']])) {
                $prod = $productPrices[$item['product_id']];
                $vatDiv = 1 + (float)($prod->vat_percent ?? 0) / 100;
                $item['unit_cogs']             = round((float)$prod->cost_price / $vatDiv, 2);
                $item['unit_cogs_source']      = 'snapshot';
                $item['revenue_account_code']  = $this->resolveRevenueAccount(
                    $prod->revenue_account_code,
                    $prod->category?->revenue_account_code,
                    $prod->item_type,
                    false
                );
            } elseif (!empty($item['service_id'])) {
                $item['revenue_account_code']  = '5113';
            }
            $order->items()->create($item);
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã cập nhật đơn hàng.');
    }

    public function process(Order $order): RedirectResponse
    {
        if ($order->status !== OrderStatus::Pending) {
            return back()->with('error', 'Chỉ có thể xử lý đơn hàng đang chờ.');
        }
        $order->update(['status' => OrderStatus::Processing]);

        return back()->with('success', 'Đơn hàng đang được xử lý.');
    }

    public function complete(Order $order): RedirectResponse
    {
        if ($order->status !== OrderStatus::Processing) {
            return back()->with('error', 'Chỉ có thể hoàn thành đơn hàng đang xử lý.');
        }
        $order->update(['status' => OrderStatus::Completed]);

        return back()->with('success', 'Đã hoàn thành đơn hàng.');
    }

    public function cancel(Order $order): RedirectResponse
    {
        if ($order->status === OrderStatus::Completed) {
            return back()->with('error', 'Không thể hủy đơn hàng đã hoàn thành.');
        }
        $order->update(['status' => OrderStatus::Cancelled]);

        return back()->with('success', 'Đã hủy đơn hàng.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $isAdmin = auth()->user()->hasRole('admin');

        if ($order->status !== OrderStatus::Cancelled) {
            if (!$isAdmin) {
                return back()->with('error', 'Chỉ có thể xóa đơn hàng đã hủy.');
            }
            if ($order->status !== OrderStatus::Pending) {
                return back()->with('error', 'Admin chỉ có thể xóa đơn hàng ở trạng thái Chờ xử lý hoặc Đã hủy.');
            }
            if ($order->stockExits()->exists()) {
                return back()->with('error', 'Không thể xóa vì đơn hàng đã có phiếu xuất kho.');
            }
            if ($order->invoices()->exists()) {
                return back()->with('error', 'Không thể xóa vì đơn hàng đã có hóa đơn.');
            }
        }

        $order->items()->delete();
        $order->delete();

        return redirect()->route('sales.orders.index')
            ->with('success', 'Đã xóa đơn hàng.');
    }

    public function forceRevert(Order $order): RedirectResponse
    {
        $this->authorize('admin.users');

        if ($order->stockExits()->where('status', 'confirmed')->exists()) {
            return back()->with('error', 'Không thể thu hồi — đơn hàng đã có phiếu xuất kho xác nhận. Vui lòng hủy phiếu xuất kho trước.');
        }
        if ($order->invoices()->whereNotIn('status', ['draft'])->exists()) {
            return back()->with('error', 'Không thể thu hồi — đơn hàng đã có hóa đơn phát sinh.');
        }

        $previous = match($order->status) {
            OrderStatus::Processing, OrderStatus::PartialDelivered => OrderStatus::Pending,
            OrderStatus::Completed   => OrderStatus::Processing,
            OrderStatus::Cancelled   => OrderStatus::Pending,
            default => null,
        };

        if (!$previous) {
            return back()->with('error', 'Không thể thu hồi đơn hàng ở trạng thái này.');
        }

        $order->update(['status' => $previous]);

        return back()->with('success', "Đã thu hồi đơn hàng về trạng thái \"{$previous->label()}\".");
    }

    public function updateDates(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('orders.manage');

        $data = $request->validate([
            'expected_delivery'   => ['nullable', 'date'],
            'customs_declared_at' => ['nullable', 'date'],
        ]);

        $update = [];
        if (array_key_exists('expected_delivery', $data)) {
            $update['expected_delivery'] = $data['expected_delivery'] ?? null;
        }
        if (array_key_exists('customs_declared_at', $data)) {
            $update['customs_declared_at'] = $data['customs_declared_at'] ?? null;
        }

        if ($update) {
            $order->update($update);
        }

        return back()->with('success', 'Đã cập nhật ngày.');
    }

    public function declareCustoms(Request $request, Order $order): RedirectResponse
    {
        $request->validate([
            'file'                => ['required', 'file', 'max:20480'],
            'customs_notes'       => ['nullable', 'string', 'max:500'],
            'customs_declared_at' => ['nullable', 'date'],
        ]);

        if ($order->customs_document_path) {
            Storage::disk('public')->delete($order->customs_document_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/customs', 'public');

        $order->update([
            'customs_status'        => CustomsStatus::Declared,
            'customs_declared_at'   => $request->filled('customs_declared_at')
                ? \Carbon\Carbon::parse($request->input('customs_declared_at'))
                : now(),
            'customs_document_path' => $path,
            'customs_document_name' => $file->getClientOriginalName(),
            'customs_notes'         => $request->input('customs_notes'),
        ]);

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã xác nhận khai báo hải quan.');
    }

    public function uploadAttachment(Request $request, Order $order): RedirectResponse
    {
        \Log::info('uploadAttachment called', ['order_id' => $order->id, 'has_file' => $request->hasFile('file')]);
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        if ($order->file_path) {
            Storage::disk('public')->delete($order->file_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/orders', 'public');

        $order->update(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã đính kèm file.');
    }

    public function deleteAttachment(Order $order): RedirectResponse
    {
        if ($order->file_path) {
            Storage::disk('public')->delete($order->file_path);
            $order->update(['file_path' => null, 'file_name' => null]);
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã xóa file đính kèm.');
    }

    private function resolveDeliveryStatus(Order $order): string
    {
        if ($order->status === OrderStatus::Cancelled) return 'cancelled';
        $undelivered = (float) ($order->undelivered_qty ?? 0);
        if ($undelivered == 0) return 'done';
        if ($order->status->value === 'partial_delivered') return 'partial';
        return 'none';
    }

    // Resolution chain khi snapshot revenue_account_code vào order_items:
    //   1. products.revenue_account_code  (override tường minh)
    //   2. product_categories.revenue_account_code  (mặc định theo danh mục)
    //   3. item_type mapping: goods→5111, service→5113
    //   4. null → InvoiceService log warning, fallback 5111
    private function resolveRevenueAccount(
        ?string $productAccount,
        ?string $categoryAccount,
        ?string $itemType,
        bool $isService
    ): string|null {
        if ($isService) return '5113';
        if ($productAccount)  return $productAccount;
        if ($categoryAccount) return $categoryAccount;
        return match($itemType ?? 'goods') {
            'goods'   => '5111',
            'service' => '5113',
            default   => null, // software/other — CẦN KẾ TOÁN XÁC NHẬN
        };
    }

    private function approvedQuotations(): array
    {
        return Quotation::where('status', QuotationStatus::Approved)
            ->with('items')
            ->orderByDesc('id')
            ->get()
            ->map(function ($q) {
                $sub = $q->subtotal();
                // Tỷ lệ chiết khấu tài liệu (doc-level) phân bổ đều cho từng dòng, trước VAT
                $docFactor = $sub > 0 ? $q->netBeforeVat() / $sub : 1;

                return [
                    'id'          => $q->id,
                    'code'        => $q->code,
                    'customer_id' => $q->customer_id,
                    'items'       => $q->items->map(fn ($i) => [
                        'product_id' => $i->product_id,
                        'service_id' => $i->service_id,
                        'name'       => $i->name,
                        'unit'       => $i->unit ?? '',
                        'quantity'   => (float) $i->quantity,
                        'unit_price' => round(
                            (float) $i->unit_price * (1 - (float) $i->discount_percent / 100) * $docFactor,
                            2
                        ),
                        'vat_rate'   => $i->vat_rate !== null ? (float) $i->vat_rate : null,
                        '_type'      => $i->product_id ? 'product' : 'service',
                    ])->values()->all(),
                ];
            })
            ->all();
    }

    public function exportExcel(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SalesOrdersExport($request->all()),
            'don-hang-ban_' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}
