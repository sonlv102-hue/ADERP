<?php

namespace App\Http\Controllers\Sales;

use App\Enums\OrderStatus;
use App\Enums\SalesReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesReturnController extends Controller
{
    public function __construct(private SalesReturnService $service) {}

    public function index(): Response
    {
        return Inertia::render('Sales/SalesReturns/Index', [
            'returns' => SalesReturn::with(['order.customer', 'warehouse', 'creator'])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($r) => [
                    'id'           => $r->id,
                    'code'         => $r->code,
                    'order_code'   => $r->order->code,
                    'customer'     => $r->order->customer->name,
                    'warehouse'    => $r->warehouse->name,
                    'return_date'  => $r->return_date->format('d/m/Y'),
                    'status'       => $r->status->value,
                    'status_label' => $r->status->label(),
                    'status_color' => $r->status->color(),
                    'items_count'  => $r->items_count,
                    'creator'      => $r->creator->name,
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $allowedStatuses = [
            OrderStatus::Processing->value,
            OrderStatus::PartialDelivered->value,
            OrderStatus::Completed->value,
        ];

        $orders = Order::with('customer')
            ->whereIn('status', $allowedStatuses)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'code'          => $o->code,
                'customer_name' => $o->customer->name,
            ]);

        $preSelectedOrderId = null;
        if ($fromOrderId = $request->query('from_order')) {
            $fromOrder = Order::find($fromOrderId);
            if ($fromOrder && in_array($fromOrder->status->value, $allowedStatuses)) {
                $preSelectedOrderId = $fromOrder->id;
            }
        }

        return Inertia::render('Sales/SalesReturns/Form', [
            'nextCode'           => SalesReturn::generateCode(),
            'orders'             => $orders,
            'warehouses'         => Warehouse::orderBy('name')->get(['id', 'name']),
            'orderItems'         => null,
            'preSelectedOrderId' => $preSelectedOrderId,
        ]);
    }

    public function orderItems(Order $order): \Illuminate\Http\JsonResponse
    {
        $order->load('items.product');

        $confirmedReturnQtys = SalesReturnItem::whereHas(
            'salesReturn',
            fn ($q) => $q->where('status', SalesReturnStatus::Confirmed)
        )->where('order_item_id', '!=', 0)
         ->selectRaw('order_item_id, SUM(quantity) as total')
         ->groupBy('order_item_id')
         ->pluck('total', 'order_item_id');

        $items = $order->items
            ->whereNotNull('product_id')
            ->filter(fn ($i) => (float) $i->delivered_quantity > 0)
            ->map(function ($i) use ($confirmedReturnQtys) {
                $priorReturned  = (float) ($confirmedReturnQtys[$i->id] ?? 0);
                $maxReturnable  = max(0, (float) $i->delivered_quantity - $priorReturned);

                // Load sold serials for this order item via stock exit items
                $serials = [];
                if ($i->product->has_serial && $maxReturnable > 0) {
                    $serials = \App\Models\ProductSerial::whereHas('stockExitItem', function ($q) use ($i) {
                        $q->whereHas('stockExit', fn ($sq) => $sq->where('order_id', $i->order_id));
                    })->where('product_id', $i->product_id)
                      ->where('status', 'sold')
                      ->get(['id', 'serial_number'])
                      ->toArray();
                }

                return [
                    'id'             => $i->id,
                    'product_id'     => $i->product_id,
                    'product_name'   => $i->product->name,
                    'product_code'   => $i->product->code,
                    'unit'           => $i->product->unit,
                    'has_serial'     => $i->product->has_serial,
                    'unit_price'     => (float) $i->unit_price,
                    'delivered_qty'  => (float) $i->delivered_quantity,
                    'prior_returned' => $priorReturned,
                    'max_returnable' => $maxReturnable,
                    'serials'        => $serials,
                ];
            })->values();

        return response()->json($items);
    }

    public function edit(SalesReturn $salesReturn): Response|RedirectResponse
    {
        if ($salesReturn->status !== SalesReturnStatus::Draft) {
            return redirect()->route('sales.sales-returns.show', $salesReturn)
                ->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $salesReturn->load(['order.customer', 'items.serials']);

        $orders = Order::with('customer')
            ->whereIn('status', [
                OrderStatus::Processing->value,
                OrderStatus::PartialDelivered->value,
                OrderStatus::Completed->value,
            ])
            ->orWhere('id', $salesReturn->order_id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'code'          => $o->code,
                'customer_name' => $o->customer->name,
            ]);

        return Inertia::render('Sales/SalesReturns/Form', [
            'salesReturn' => [
                'id'           => $salesReturn->id,
                'code'         => $salesReturn->code,
                'order_id'     => $salesReturn->order_id,
                'warehouse_id' => $salesReturn->warehouse_id,
                'return_date'  => $salesReturn->return_date->format('Y-m-d'),
                'reason'       => $salesReturn->reason,
                'notes'        => $salesReturn->notes,
                'items'        => $salesReturn->items->map(fn ($item) => [
                    'order_item_id' => $item->order_item_id,
                    'product_id'    => $item->product_id,
                    'quantity'      => $item->quantity,
                    'unit_price'    => (float) $item->unit_price,
                    'serial_ids'    => $item->serials->pluck('id')->toArray(),
                ])->values()->toArray(),
            ],
            'orders'     => $orders,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        if ($salesReturn->status !== SalesReturnStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'warehouse_id'                => ['required', 'exists:warehouses,id'],
            'return_date'                 => ['required', 'date'],
            'reason'                      => ['nullable', 'string'],
            'notes'                       => ['nullable', 'string'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.order_item_id'       => ['required', 'exists:order_items,id'],
            'items.*.product_id'          => ['required', 'exists:products,id'],
            'items.*.quantity'            => ['required', 'integer', 'min:1'],
            'items.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'          => ['nullable', 'array'],
            'items.*.serial_ids.*'        => ['integer', 'exists:product_serials,id'],
        ]);

        DB::transaction(function () use ($data, $salesReturn) {
            \App\Models\ProductSerial::whereHas('salesReturnItem', fn ($q) => $q->where('sales_return_id', $salesReturn->id))
                ->update(['sales_return_item_id' => null]);

            $salesReturn->items()->delete();

            $salesReturn->update([
                'warehouse_id' => $data['warehouse_id'],
                'return_date'  => $data['return_date'],
                'reason'       => $data['reason'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $returnItem = $salesReturn->items()->create([
                    'order_item_id' => $item['order_item_id'],
                    'product_id'    => $item['product_id'],
                    'quantity'      => $item['quantity'],
                    'unit_price'    => $item['unit_price'],
                ]);

                if (!empty($item['serial_ids'])) {
                    \App\Models\ProductSerial::whereIn('id', $item['serial_ids'])
                        ->update(['sales_return_item_id' => $returnItem->id]);
                }
            }
        });

        return redirect()->route('sales.sales-returns.show', $salesReturn)
            ->with('success', 'Đã cập nhật phiếu trả hàng bán.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                    => ['required', 'string', 'unique:sales_returns,code'],
            'order_id'                => ['required', 'exists:orders,id'],
            'warehouse_id'            => ['required', 'exists:warehouses,id'],
            'return_date'             => ['required', 'date'],
            'reason'                  => ['nullable', 'string'],
            'notes'                   => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.order_item_id'   => ['required', 'exists:order_items,id'],
            'items.*.product_id'      => ['required', 'exists:products,id'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'      => ['nullable', 'array'],
            'items.*.serial_ids.*'    => ['integer', 'exists:product_serials,id'],
        ]);

        // Validate order items belong to the order
        $order = Order::with('items.product')->findOrFail($data['order_id']);
        $orderItemIds = $order->items->pluck('id')->toArray();
        foreach ($data['items'] as $idx => $item) {
            if (! in_array($item['order_item_id'], $orderItemIds)) {
                return back()->withErrors(["items.{$idx}.order_item_id" => 'Dòng hàng không thuộc đơn hàng này.'])->withInput();
            }
            if (!empty($item['serial_ids'])) {
                $validCount = \App\Models\ProductSerial::whereIn('id', $item['serial_ids'])
                    ->where('product_id', $item['product_id'])
                    ->whereIn('status', ['sold'])
                    ->count();
                if ($validCount !== count($item['serial_ids'])) {
                    return back()->withErrors(["items.{$idx}.serial_ids" => 'Một số serial không hợp lệ.'])->withInput();
                }
            }
        }

        $salesReturn = DB::transaction(function () use ($data) {
            $return = SalesReturn::create([
                'code'         => $data['code'],
                'order_id'     => $data['order_id'],
                'warehouse_id' => $data['warehouse_id'],
                'return_date'  => $data['return_date'],
                'status'       => SalesReturnStatus::Draft,
                'reason'       => $data['reason'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'created_by'   => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                $returnItem = $return->items()->create([
                    'order_item_id' => $item['order_item_id'],
                    'product_id'    => $item['product_id'],
                    'quantity'      => $item['quantity'],
                    'unit_price'    => $item['unit_price'],
                ]);

                // Link selected serials to this return item
                if (! empty($item['serial_ids'])) {
                    \App\Models\ProductSerial::whereIn('id', $item['serial_ids'])
                        ->update(['sales_return_item_id' => $returnItem->id]);
                }
            }

            return $return;
        });

        return redirect()->route('sales.sales-returns.show', $salesReturn)
            ->with('success', 'Đã tạo phiếu trả hàng bán.');
    }

    public function show(SalesReturn $salesReturn): Response
    {
        $salesReturn->load(['order.customer', 'warehouse', 'creator', 'items.product', 'items.serials']);

        return Inertia::render('Sales/SalesReturns/Show', [
            'salesReturn' => [
                'id'           => $salesReturn->id,
                'code'         => $salesReturn->code,
                'return_date'  => $salesReturn->return_date->format('d/m/Y'),
                'status'       => $salesReturn->status->value,
                'status_label' => $salesReturn->status->label(),
                'status_color' => $salesReturn->status->color(),
                'reason'       => $salesReturn->reason,
                'notes'        => $salesReturn->notes,
                'order'        => [
                    'id'   => $salesReturn->order->id,
                    'code' => $salesReturn->order->code,
                ],
                'customer'     => $salesReturn->order->customer->name,
                'warehouse'    => $salesReturn->warehouse->name,
                'creator'      => $salesReturn->creator->name,
                'items'        => $salesReturn->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_name' => $item->product->name,
                    'product_code' => $item->product->code,
                    'unit'         => $item->product->unit,
                    'has_serial'   => $item->product->has_serial,
                    'quantity'     => $item->quantity,
                    'unit_price'   => (float) $item->unit_price,
                    'total'        => $item->quantity * $item->unit_price,
                    'serials'      => $item->serials->map(fn ($s) => [
                        'serial_number' => $s->serial_number,
                        'status'        => $s->status->value,
                        'status_label'  => $s->status->label(),
                        'status_color'  => $s->status->color(),
                    ]),
                ]),
            ],
        ]);
    }

    public function confirm(SalesReturn $salesReturn): RedirectResponse
    {
        try {
            $this->service->confirmReturn($salesReturn);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xác nhận phiếu trả hàng bán.');
    }

    public function cancel(SalesReturn $salesReturn): RedirectResponse
    {
        try {
            $this->service->cancelReturn($salesReturn);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy phiếu trả hàng bán.');
    }

    public function destroy(SalesReturn $salesReturn): RedirectResponse
    {
        if (! in_array($salesReturn->status, [SalesReturnStatus::Draft, SalesReturnStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa phiếu ở trạng thái nháp hoặc đã hủy.');
        }

        DB::transaction(function () use ($salesReturn) {
            // Unlink serials
            \App\Models\ProductSerial::whereHas('salesReturnItem', fn ($q) => $q->where('sales_return_id', $salesReturn->id))
                ->update(['sales_return_item_id' => null]);

            $salesReturn->items()->delete();
            $salesReturn->delete();
        });

        return redirect()->route('sales.sales-returns.index')
            ->with('success', 'Đã xóa phiếu trả hàng bán.');
    }
}
