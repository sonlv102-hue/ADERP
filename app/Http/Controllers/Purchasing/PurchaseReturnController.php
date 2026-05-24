<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockEntryItem;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service) {}

    public function index(): Response
    {
        $returns = PurchaseReturn::with(['purchaseOrder', 'supplier', 'warehouse', 'creator'])
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn ($r) => [
                'id'           => $r->id,
                'code'         => $r->code,
                'po_code'      => $r->purchaseOrder->code,
                'supplier'     => $r->supplier->name,
                'warehouse'    => $r->warehouse->name,
                'return_date'  => $r->return_date->format('d/m/Y'),
                'status'       => $r->status->value,
                'status_label' => $r->status->label(),
                'status_color' => $r->status->color(),
                'creator'      => $r->creator->name,
            ]);

        return Inertia::render('Purchasing/PurchaseReturns/Index', compact('returns'));
    }

    public function create(): Response
    {
        $purchaseOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', [
                PurchaseOrderStatus::PartialReceived->value,
                PurchaseOrderStatus::Received->value,
            ])
            ->orderByDesc('id')
            ->get(['id', 'code', 'supplier_id', 'warehouse_id', 'status']);

        return Inertia::render('Purchasing/PurchaseReturns/Form', [
            'nextCode'       => PurchaseReturn::generateCode(),
            'purchaseOrders' => $purchaseOrders->map(fn ($po) => [
                'id'           => $po->id,
                'code'         => $po->code,
                'supplier_id'  => $po->supplier_id,
                'supplier'     => $po->supplier->name,
                'warehouse_id' => $po->warehouse_id,
            ]),
            'warehouses'     => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function poItems(PurchaseOrder $purchaseOrder): \Illuminate\Http\JsonResponse
    {
        $purchaseOrder->load('items.product');

        $confirmedEntryIds = \App\Models\StockEntry::where('purchase_order_id', $purchaseOrder->id)
            ->where('status', 'confirmed')
            ->pluck('id');

        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $items = $purchaseOrder->items->map(function ($poItem) use ($receivedQtys) {
            $totalReceived = (int) ($receivedQtys[$poItem->product_id] ?? 0);

            $priorReturned = PurchaseReturnItem::whereHas(
                'purchaseReturn',
                fn ($q) => $q->where('purchase_order_id', $poItem->purchase_order_id)
                             ->where('status', PurchaseReturnStatus::Confirmed)
            )->where('purchase_order_item_id', $poItem->id)->sum('quantity');

            return [
                'id'                   => $poItem->id,
                'product_id'           => $poItem->product_id,
                'product_code'         => $poItem->product?->code ?? '—',
                'product_name'         => $poItem->product?->name ?? '(đã xóa)',
                'unit'                 => $poItem->product?->unit ?? '',
                'has_serial'           => (bool) ($poItem->product?->has_serial ?? false),
                'ordered_qty'          => $poItem->quantity,
                'total_received'       => $totalReceived,
                'prior_returned'       => (int) $priorReturned,
                'max_returnable'       => max(0, $totalReceived - (int) $priorReturned),
                'unit_price'           => $poItem->unit_price,
            ];
        });

        return response()->json($items);
    }

    public function edit(PurchaseReturn $purchaseReturn): Response|RedirectResponse
    {
        if ($purchaseReturn->status !== PurchaseReturnStatus::Draft) {
            return redirect()->route('purchasing.purchase-returns.show', $purchaseReturn)
                ->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $purchaseReturn->load(['purchaseOrder', 'items.serials']);

        $purchaseOrders = PurchaseOrder::with('supplier')
            ->where(fn ($q) => $q->whereIn('status', [
                PurchaseOrderStatus::PartialReceived->value,
                PurchaseOrderStatus::Received->value,
            ])->orWhere('id', $purchaseReturn->purchase_order_id))
            ->orderByDesc('id')
            ->get(['id', 'code', 'supplier_id', 'warehouse_id', 'status']);

        return Inertia::render('Purchasing/PurchaseReturns/Form', [
            'purchaseReturn' => [
                'id'                => $purchaseReturn->id,
                'code'              => $purchaseReturn->code,
                'purchase_order_id' => $purchaseReturn->purchase_order_id,
                'po_code'           => $purchaseReturn->purchaseOrder->code,
                'warehouse_id'      => $purchaseReturn->warehouse_id,
                'return_date'       => $purchaseReturn->return_date->format('Y-m-d'),
                'reason'            => $purchaseReturn->reason,
                'notes'             => $purchaseReturn->notes,
                'items'             => $purchaseReturn->items->map(fn ($item) => [
                    'purchase_order_item_id' => $item->purchase_order_item_id,
                    'product_id'             => $item->product_id,
                    'quantity'               => $item->quantity,
                    'unit_price'             => $item->unit_price,
                    'serials'                => $item->serials->pluck('serial_number')->toArray(),
                ])->values()->toArray(),
            ],
            'purchaseOrders' => $purchaseOrders->map(fn ($po) => [
                'id'           => $po->id,
                'code'         => $po->code,
                'supplier_id'  => $po->supplier_id,
                'supplier'     => $po->supplier->name,
                'warehouse_id' => $po->warehouse_id,
            ]),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        if ($purchaseReturn->status !== PurchaseReturnStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'warehouse_id'  => ['required', 'exists:warehouses,id'],
            'return_date'   => ['required', 'date'],
            'reason'        => ['nullable', 'string'],
            'notes'         => ['nullable', 'string'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'exists:purchase_order_items,id'],
            'items.*.product_id'             => ['required', 'exists:products,id'],
            'items.*.quantity'               => ['required', 'integer', 'min:1'],
            'items.*.unit_price'             => ['nullable', 'numeric', 'min:0'],
            'items.*.serials'                => ['nullable', 'array'],
            'items.*.serials.*'              => ['string'],
        ]);

        DB::transaction(function () use ($data, $purchaseReturn) {
            $oldItemIds = $purchaseReturn->items()->pluck('id');
            \App\Models\ProductSerial::whereIn('purchase_return_item_id', $oldItemIds)
                ->update(['purchase_return_item_id' => null]);

            $purchaseReturn->items()->delete();

            $purchaseReturn->update([
                'warehouse_id' => $data['warehouse_id'],
                'return_date'  => $data['return_date'],
                'reason'       => $data['reason'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $returnItem = $purchaseReturn->items()->create([
                    'purchase_order_item_id' => $itemData['purchase_order_item_id'],
                    'product_id'             => $itemData['product_id'],
                    'quantity'               => $itemData['quantity'],
                    'unit_price'             => $itemData['unit_price'] ?? null,
                ]);

                if (!empty($itemData['serials'])) {
                    \App\Models\ProductSerial::where('warehouse_id', $data['warehouse_id'])
                        ->where('product_id', $itemData['product_id'])
                        ->where('status', 'in_stock')
                        ->whereIn('serial_number', $itemData['serials'])
                        ->update(['purchase_return_item_id' => $returnItem->id]);
                }
            }
        });

        return redirect()->route('purchasing.purchase-returns.show', $purchaseReturn)
            ->with('success', 'Đã cập nhật phiếu trả hàng mua.');
    }

    public function destroy(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        if (!in_array($purchaseReturn->status, [PurchaseReturnStatus::Draft, PurchaseReturnStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa phiếu ở trạng thái nháp hoặc đã hủy.');
        }

        DB::transaction(function () use ($purchaseReturn) {
            $itemIds = $purchaseReturn->items()->pluck('id');
            \App\Models\ProductSerial::whereIn('purchase_return_item_id', $itemIds)
                ->update(['purchase_return_item_id' => null]);

            $purchaseReturn->items()->delete();
            $purchaseReturn->delete();
        });

        return redirect()->route('purchasing.purchase-returns.index')
            ->with('success', 'Đã xóa phiếu trả hàng mua.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'unique:purchase_returns,code'],
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'warehouse_id'     => ['required', 'exists:warehouses,id'],
            'return_date'      => ['required', 'date'],
            'reason'           => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
            'items'            => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'exists:purchase_order_items,id'],
            'items.*.product_id'             => ['required', 'exists:products,id'],
            'items.*.quantity'               => ['required', 'integer', 'min:1'],
            'items.*.unit_price'             => ['nullable', 'numeric', 'min:0'],
            'items.*.serials'                => ['nullable', 'array'],
            'items.*.serials.*'              => ['string'],
        ]);

        $po = PurchaseOrder::findOrFail($data['purchase_order_id']);

        $return = DB::transaction(function () use ($data, $po) {
            $purchaseReturn = PurchaseReturn::create([
                'code'              => $data['code'],
                'purchase_order_id' => $data['purchase_order_id'],
                'supplier_id'       => $po->supplier_id,
                'warehouse_id'      => $data['warehouse_id'],
                'return_date'       => $data['return_date'],
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => auth()->id(),
            ]);

            foreach ($data['items'] as $itemData) {
                $returnItem = $purchaseReturn->items()->create([
                    'purchase_order_item_id' => $itemData['purchase_order_item_id'],
                    'product_id'             => $itemData['product_id'],
                    'quantity'               => $itemData['quantity'],
                    'unit_price'             => $itemData['unit_price'] ?? null,
                ]);

                // Link selected serials to this return item
                if (!empty($itemData['serials'])) {
                    \App\Models\ProductSerial::where('warehouse_id', $data['warehouse_id'])
                        ->where('product_id', $itemData['product_id'])
                        ->where('status', 'in_stock')
                        ->whereIn('serial_number', $itemData['serials'])
                        ->update(['purchase_return_item_id' => $returnItem->id]);
                }
            }

            return $purchaseReturn;
        });

        return redirect()->route('purchasing.purchase-returns.show', $return)
            ->with('success', 'Đã tạo phiếu trả hàng mua.');
    }

    public function show(PurchaseReturn $purchaseReturn): Response
    {
        $purchaseReturn->load([
            'purchaseOrder', 'supplier', 'warehouse', 'creator',
            'items.product',
            'items.serials',
        ]);

        return Inertia::render('Purchasing/PurchaseReturns/Show', [
            'return' => [
                'id'           => $purchaseReturn->id,
                'code'         => $purchaseReturn->code,
                'po_code'      => $purchaseReturn->purchaseOrder->code,
                'po_id'        => $purchaseReturn->purchase_order_id,
                'supplier'     => $purchaseReturn->supplier->name,
                'warehouse'    => $purchaseReturn->warehouse->name,
                'return_date'  => $purchaseReturn->return_date->format('d/m/Y'),
                'status'       => $purchaseReturn->status->value,
                'status_label' => $purchaseReturn->status->label(),
                'status_color' => $purchaseReturn->status->color(),
                'reason'       => $purchaseReturn->reason,
                'notes'        => $purchaseReturn->notes,
                'creator'      => $purchaseReturn->creator->name,
                'items'        => $purchaseReturn->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_code' => $item->product?->code ?? '—',
                    'product_name' => $item->product?->name ?? '(đã xóa)',
                    'unit'         => $item->product?->unit ?? '',
                    'quantity'     => $item->quantity,
                    'unit_price'   => $item->unit_price,
                    'total'        => $item->unit_price ? $item->quantity * $item->unit_price : null,
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

    public function confirm(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        try {
            $this->service->confirmReturn($purchaseReturn);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xác nhận phiếu trả hàng mua. Tồn kho đã được cập nhật.');
    }

    public function cancel(PurchaseReturn $purchaseReturn): RedirectResponse
    {
        try {
            $this->service->cancelReturn($purchaseReturn);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy phiếu trả hàng mua.');
    }
}
