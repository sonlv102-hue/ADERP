<?php

namespace App\Http\Controllers\Sales;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sales/Orders/Index', [
            'orders' => Order::with(['customer', 'creator', 'quotation'])
                ->withCount('items')
                ->addSelect([
                    'items_total' => OrderItem::selectRaw('COALESCE(SUM(quantity * unit_price), 0)')
                        ->whereColumn('order_id', 'orders.id'),
                ])
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($o) => [
                    'id'             => $o->id,
                    'code'           => $o->code,
                    'customer'       => $o->customer->name,
                    'order_date'     => $o->order_date->format('d/m/Y'),
                    'expected_delivery' => $o->expected_delivery?->format('d/m/Y'),
                    'status'         => $o->status->value,
                    'status_label'   => $o->status->label(),
                    'status_color'   => $o->status->color(),
                    'creator'        => $o->creator->name,
                    'quotation_code' => $o->quotation?->code,
                    'items_count'    => $o->items_count,
                    'total'          => (float) $o->items_total,
                ]),
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
            'nextCode'        => Order::generateCode(),
            'customers'       => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'products'        => Product::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'sell_price']),
            'services'        => Service::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'price']),
            'priceLists'      => PriceList::select('id', 'code', 'name')->orderBy('name')->get(),
            'supplementaryFor' => $supplementaryFor,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                        => ['required', 'string', 'unique:orders,code'],
            'customer_id'                 => ['required', 'exists:customers,id'],
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
        ]);

        $order = Order::create([
            'code'                        => $data['code'],
            'customer_id'                 => $data['customer_id'],
            'supplementary_for_order_id'  => $data['supplementary_for_order_id'] ?? null,
            'order_date'                  => $data['order_date'],
            'expected_delivery'           => $data['expected_delivery'] ?? null,
            'notes'                       => $data['notes'] ?? null,
            'created_by'                  => auth()->id(),
            'status'                      => OrderStatus::Pending,
        ]);

        foreach ($data['items'] as $item) {
            $order->items()->create($item);
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã tạo đơn hàng.');
    }

    public function show(Order $order): Response
    {
        $order->load(['customer', 'creator', 'quotation', 'items.product', 'items.service', 'contracts']);

        return Inertia::render('Sales/Orders/Show', [
            'order' => [
                'id'                => $order->id,
                'code'              => $order->code,
                'customer'          => ['id' => $order->customer->id, 'name' => $order->customer->name],
                'quotation'         => $order->quotation ? ['id' => $order->quotation->id, 'code' => $order->quotation->code] : null,
                'order_date'        => $order->order_date->format('d/m/Y'),
                'expected_delivery' => $order->expected_delivery?->format('d/m/Y'),
                'status'            => $order->status->value,
                'status_label'      => $order->status->label(),
                'status_color'      => $order->status->color(),
                'notes'             => $order->notes,
                'creator'           => $order->creator->name,
                'created_at'        => $order->created_at->format('d/m/Y'),
                'total'             => $order->total(),
                'items'             => $order->items->map(fn ($item) => [
                    'id'                 => $item->id,
                    'name'               => $item->name,
                    'unit'               => $item->unit,
                    'quantity'           => $item->quantity,
                    'delivered_quantity' => $item->delivered_quantity,
                    'remaining'          => max(0, (float)$item->quantity - (float)$item->delivered_quantity),
                    'unit_price'         => $item->unit_price,
                    'line_total'         => $item->lineTotal(),
                ]),
                'contracts' => $order->contracts->map(fn ($c) => [
                    'id'   => $c->id,
                    'code' => $c->code,
                ]),
                'file_name' => $order->file_name,
                'file_url'  => $order->file_path ? Storage::disk('public')->url($order->file_path) : null,
            ],
        ]);
    }

    public function edit(Order $order): Response
    {
        abort_if(
            in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled]),
            403,
            'Không thể sửa đơn hàng đã hoàn thành hoặc đã hủy.'
        );

        $order->load('items');

        return Inertia::render('Sales/Orders/Form', [
            'order'     => [
                'id'                => $order->id,
                'code'              => $order->code,
                'customer_id'       => $order->customer_id,
                'order_date'        => $order->order_date->format('Y-m-d'),
                'expected_delivery' => $order->expected_delivery?->format('Y-m-d'),
                'notes'             => $order->notes,
                'items'             => $order->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'service_id' => $item->service_id,
                    'name'       => $item->name,
                    'unit'       => $item->unit,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->unit_price,
                    '_type'      => $item->product_id ? 'product' : 'service',
                ]),
            ],
            'customers' => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'products'  => Product::orderBy('name')->get(['id', 'code', 'name', 'unit', 'sell_price']),
            'services'  => Service::orderBy('name')->get(['id', 'code', 'name', 'unit', 'price']),
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
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
        ]);

        $order->update([
            'customer_id'       => $data['customer_id'],
            'order_date'        => $data['order_date'],
            'expected_delivery' => $data['expected_delivery'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);

        $order->items()->delete();
        foreach ($data['items'] as $item) {
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
        if ($order->status !== OrderStatus::Cancelled) {
            return back()->with('error', 'Chỉ có thể xóa đơn hàng đã hủy.');
        }

        $order->items()->delete();
        $order->delete();

        return redirect()->route('sales.orders.index')
            ->with('success', 'Đã xóa đơn hàng.');
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
}
