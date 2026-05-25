<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockEntryStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseContract;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockEntryController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private PurchaseOrderService $purchaseOrderService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Warehouse/StockEntries/Index', [
            'entries' => StockEntry::with(['warehouse', 'supplier', 'creator'])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($e) => [
                    'id' => $e->id,
                    'code' => $e->code,
                    'entry_date' => $e->entry_date->format('d/m/Y'),
                    'status' => $e->status->value,
                    'status_label' => $e->status->label(),
                    'status_color' => $e->status->color(),
                    'warehouse' => $e->warehouse->name,
                    'supplier' => $e->supplier?->name,
                    'creator' => $e->creator->name,
                    'items_count' => $e->items_count,
                ]),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $poId = $request->query('purchase_order_id');

        if (!$poId) {
            return redirect()->route('purchasing.purchase-orders.index')
                ->with('error', 'Vui lòng tạo phiếu nhập kho từ đơn mua hàng.');
        }

        $po = PurchaseOrder::with(['items.product', 'supplier', 'warehouse'])->findOrFail($poId);

        if (!in_array($po->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartialReceived])) {
            return redirect()->route('purchasing.purchase-orders.show', $po)
                ->with('error', 'Đơn mua hàng không thể nhận hàng ở trạng thái hiện tại.');
        }

        $confirmedEntryIds = StockEntry::where('purchase_order_id', $po->id)
            ->where('status', StockEntryStatus::Confirmed)
            ->pluck('id');
        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $poItems = $po->items->map(fn ($item) => [
            'product_id'    => $item->product_id,
            'product_code'  => $item->product->code,
            'product_name'  => $item->product->name,
            'unit'          => $item->product->unit,
            'has_serial'    => $item->product->has_serial,
            'ordered_qty'   => $item->quantity,
            'received_qty'  => (int) ($receivedQtys[$item->product_id] ?? 0),
            'remaining_qty' => $item->quantity - (int) ($receivedQtys[$item->product_id] ?? 0),
            'unit_price'    => (float) $item->unit_price,
        ])->filter(fn ($item) => $item['remaining_qty'] > 0)->values();

        if ($poItems->isEmpty()) {
            return redirect()->route('purchasing.purchase-orders.show', $po)
                ->with('info', 'Đơn mua hàng này đã nhận đủ hàng.');
        }

        $hasPurchaseContract = PurchaseContract::where('purchase_order_id', $po->id)->exists();

        return Inertia::render('Warehouse/StockEntries/Form', [
            'nextCode'            => StockEntry::generateCode(),
            'hasPurchaseContract' => $hasPurchaseContract,
            'purchaseOrder'       => [
                'id'           => $po->id,
                'code'         => $po->code,
                'supplier'     => $po->supplier->name,
                'warehouse_id' => $po->warehouse_id,
                'warehouse'    => $po->warehouse->name,
                'items'        => $poItems,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $poId = $request->input('purchase_order_id');
        $po = PurchaseOrder::with('items.product')->find($poId);

        if (!$po || !in_array($po->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartialReceived])) {
            return back()->with('error', 'Đơn mua hàng không hợp lệ hoặc không ở trạng thái hợp lệ.');
        }

        $data = $request->validate([
            'purchase_order_id'   => ['required', 'exists:purchase_orders,id'],
            'code'                => ['required', 'string', 'unique:stock_entries,code'],
            'entry_date'          => ['required', 'date'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.serials'     => ['nullable', 'array'],
            'items.*.serials.*'   => ['nullable', 'string', 'max:100'],
        ]);

        // Calculate remaining quantities from already-confirmed entries
        $confirmedEntryIds = StockEntry::where('purchase_order_id', $po->id)
            ->where('status', StockEntryStatus::Confirmed)
            ->pluck('id');
        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $poProductMap = $po->items->keyBy('product_id');

        $errors = [];
        $allSerials = [];

        foreach ($data['items'] as $idx => $item) {
            $poItem = $poProductMap[$item['product_id']] ?? null;
            if (!$poItem) {
                $errors["items.{$idx}.product_id"] = 'Sản phẩm không có trong đơn mua hàng.';
                continue;
            }
            $remaining = $poItem->quantity - (int) ($receivedQtys[$item['product_id']] ?? 0);
            if ((int) $item['quantity'] > $remaining) {
                $unit = $poItem->product->unit ?? '';
                $errors["items.{$idx}.quantity"] = "Số lượng vượt quá còn lại ({$remaining} {$unit}).";
            }

            $product = Product::find($item['product_id']);
            if ($product) {
                $serials = array_values(array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null));
                if (count($serials) !== (int) $item['quantity']) {
                    $errors["items.{$idx}.serials"] = "Cần nhập đúng {$item['quantity']} số serial cho \"{$product->name}\".";
                }
                foreach ($serials as $serial) {
                    if (in_array($serial, $allSerials)) {
                        $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" bị trùng trong phiếu này.";
                        break;
                    }
                    if (ProductSerial::where('serial_number', $serial)->exists()) {
                        $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" đã tồn tại trong hệ thống.";
                        break;
                    }
                    $allSerials[] = $serial;
                }
            }
        }

        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        $entry = DB::transaction(function () use ($data, $po) {
            $entry = StockEntry::create([
                'code'              => $data['code'],
                'warehouse_id'      => $po->warehouse_id,
                'supplier_id'       => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'created_by'        => auth()->id(),
                'entry_date'        => $data['entry_date'],
                'notes'             => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $entryItem = $entry->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                $serials = array_values(array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null));
                foreach ($serials as $serialNumber) {
                    ProductSerial::create([
                        'product_id'          => $item['product_id'],
                        'warehouse_id'        => $po->warehouse_id,
                        'serial_number'       => $serialNumber,
                        'stock_entry_item_id' => $entryItem->id,
                        'status'              => 'in_stock',
                    ]);
                }
            }

            return $entry;
        });

        return redirect()->route('warehouse.stock-entries.show', $entry)
            ->with('success', 'Đã tạo phiếu nhập kho.');
    }

    public function edit(StockEntry $stockEntry): Response|RedirectResponse
    {
        if ($stockEntry->status !== StockEntryStatus::Draft) {
            return redirect()->route('warehouse.stock-entries.show', $stockEntry)
                ->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $po = PurchaseOrder::with(['items.product', 'supplier', 'warehouse'])
            ->find($stockEntry->purchase_order_id);

        if (! $po) {
            return redirect()->route('warehouse.stock-entries.show', $stockEntry)
                ->with('error', 'Không tìm thấy đơn mua hàng liên kết.');
        }

        $confirmedEntryIds = StockEntry::where('purchase_order_id', $po->id)
            ->where('status', StockEntryStatus::Confirmed)
            ->pluck('id');
        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $poItems = $po->items->map(fn ($item) => [
            'product_id'    => $item->product_id,
            'product_code'  => $item->product->code,
            'product_name'  => $item->product->name,
            'unit'          => $item->product->unit,
            'has_serial'    => $item->product->has_serial,
            'ordered_qty'   => $item->quantity,
            'received_qty'  => (int) ($receivedQtys[$item->product_id] ?? 0),
            'remaining_qty' => $item->quantity - (int) ($receivedQtys[$item->product_id] ?? 0),
            'unit_price'    => (float) $item->unit_price,
        ])->values();

        $hasPurchaseContract = PurchaseContract::where('purchase_order_id', $po->id)->exists();

        $stockEntry->load('items.serials');
        $entryItemMap = $stockEntry->items->keyBy('product_id');

        return Inertia::render('Warehouse/StockEntries/Form', [
            'nextCode'            => $stockEntry->code,
            'hasPurchaseContract' => $hasPurchaseContract,
            'purchaseOrder'       => [
                'id'           => $po->id,
                'code'         => $po->code,
                'supplier'     => $po->supplier->name,
                'warehouse_id' => $po->warehouse_id,
                'warehouse'    => $po->warehouse->name,
                'items'        => $poItems,
            ],
            'entry' => [
                'id'         => $stockEntry->id,
                'code'       => $stockEntry->code,
                'entry_date' => $stockEntry->entry_date->format('Y-m-d'),
                'notes'      => $stockEntry->notes,
                'items'      => $poItems->map(fn ($pi) => [
                    'product_id' => $pi['product_id'],
                    'quantity'   => (int) ($entryItemMap[$pi['product_id']]?->quantity ?? 0),
                    'unit_price' => (float) ($entryItemMap[$pi['product_id']]?->unit_price ?? $pi['unit_price']),
                    'serials'    => $entryItemMap[$pi['product_id']]?->serials->pluck('serial_number')->toArray() ?? [],
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, StockEntry $stockEntry): RedirectResponse
    {
        if ($stockEntry->status !== StockEntryStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $po = PurchaseOrder::with('items.product')->find($stockEntry->purchase_order_id);
        if (! $po || ! in_array($po->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartialReceived])) {
            return back()->with('error', 'Đơn mua hàng không hợp lệ hoặc không ở trạng thái hợp lệ.');
        }

        $data = $request->validate([
            'code'                => ['required', 'string', Rule::unique('stock_entries', 'code')->ignore($stockEntry->id)],
            'entry_date'          => ['required', 'date'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.serials'     => ['nullable', 'array'],
            'items.*.serials.*'   => ['nullable', 'string', 'max:100'],
        ]);

        $confirmedEntryIds = StockEntry::where('purchase_order_id', $po->id)
            ->where('status', StockEntryStatus::Confirmed)
            ->pluck('id');
        $receivedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedEntryIds)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $poProductMap = $po->items->keyBy('product_id');
        $stockEntry->load('items');
        $oldItemIds = $stockEntry->items->pluck('id');
        $oldSerialNumbers = ProductSerial::whereIn('stock_entry_item_id', $oldItemIds)
            ->pluck('serial_number')
            ->toArray();

        $errors = [];
        $allSerials = [];

        foreach ($data['items'] as $idx => $item) {
            $poItem = $poProductMap[$item['product_id']] ?? null;
            if (! $poItem) {
                $errors["items.{$idx}.product_id"] = 'Sản phẩm không có trong đơn mua hàng.';
                continue;
            }

            $remaining = $poItem->quantity - (int) ($receivedQtys[$item['product_id']] ?? 0);
            if ((int) $item['quantity'] > $remaining) {
                $unit = $poItem->product->unit ?? '';
                $errors["items.{$idx}.quantity"] = "Số lượng vượt quá còn lại ({$remaining} {$unit}).";
            }

            $serials = array_values(array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null));
            if (count($serials) !== (int) $item['quantity']) {
                $errors["items.{$idx}.serials"] = "Cần nhập đúng {$item['quantity']} số serial cho \"{$poItem->product->name}\".";
            }

            foreach ($serials as $serial) {
                if (in_array($serial, $allSerials)) {
                    $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" bị trùng trong phiếu này.";
                }
                $allSerials[] = $serial;

                if (! in_array($serial, $oldSerialNumbers)) {
                    if (ProductSerial::where('serial_number', $serial)->exists()) {
                        $errors["items.{$idx}.serials"] = "Số serial \"{$serial}\" đã tồn tại trong hệ thống.";
                    }
                }
            }
        }

        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        DB::transaction(function () use ($data, $stockEntry, $po, $oldItemIds) {
            ProductSerial::whereIn('stock_entry_item_id', $oldItemIds)->delete();
            $stockEntry->items()->delete();

            $stockEntry->update([
                'code'       => $data['code'],
                'entry_date' => $data['entry_date'],
                'notes'      => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $entryItem = $stockEntry->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                $serials = array_values(array_filter($item['serials'] ?? [], fn ($s) => $s !== '' && $s !== null));
                foreach ($serials as $serialNumber) {
                    ProductSerial::create([
                        'product_id'          => $item['product_id'],
                        'warehouse_id'        => $po->warehouse_id,
                        'serial_number'       => $serialNumber,
                        'stock_entry_item_id' => $entryItem->id,
                        'status'              => 'in_stock',
                    ]);
                }
            }
        });

        return redirect()->route('warehouse.stock-entries.show', $stockEntry)
            ->with('success', 'Đã cập nhật phiếu nhập kho.');
    }

    public function show(StockEntry $stockEntry): Response
    {
        return Inertia::render('Warehouse/StockEntries/Show', [
            'entry' => [
                'id' => $stockEntry->id,
                'code' => $stockEntry->code,
                'entry_date' => $stockEntry->entry_date->format('d/m/Y'),
                'status' => $stockEntry->status->value,
                'status_label' => $stockEntry->status->label(),
                'status_color' => $stockEntry->status->color(),
                'warehouse' => $stockEntry->warehouse->name,
                'supplier' => $stockEntry->supplier?->name,
                'creator' => $stockEntry->creator->name,
                'notes' => $stockEntry->notes,
                'items' => $stockEntry->items->load('serials')->map(fn ($item) => [
                    'id' => $item->id,
                    'product_code' => $item->product->code,
                    'product_name' => $item->product->name,
                    'unit' => $item->product->unit,
                    'has_serial' => $item->product->has_serial,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total' => $item->quantity * $item->unit_price,
                    'serials' => $item->serials->map(fn ($s) => [
                        'serial_number' => $s->serial_number,
                        'status' => $s->status->value,
                        'status_label' => $s->status->label(),
                        'status_color' => $s->status->color(),
                    ]),
                ]),
            ],
        ]);
    }

    public function pdf(StockEntry $stockEntry)
    {
        $stockEntry->load(['warehouse', 'supplier', 'creator', 'items.product', 'items.serials']);
        $pdf = Pdf::loadView('pdf.stock_entry', compact('stockEntry'))->setPaper('a4', 'portrait');
        return $pdf->stream("PhieuNhapKho-{$stockEntry->code}.pdf");
    }

    public function exportPdf(Request $request)
    {
        $entries = StockEntry::with(['warehouse', 'supplier', 'creator'])
            ->withCount('items')
            ->orderByDesc('id')
            ->get();
        $pdf = Pdf::loadView('pdf.stock_entry_list', compact('entries'))->setPaper('a4', 'landscape');
        return $pdf->stream('DanhSachPhieuNhapKho.pdf');
    }

    public function confirm(StockEntry $stockEntry): RedirectResponse
    {
        try {
            $this->stockService->confirmEntry($stockEntry);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($stockEntry->purchase_order_id) {
            $po = PurchaseOrder::find($stockEntry->purchase_order_id);
            if ($po) $this->purchaseOrderService->syncReceiveStatus($po);
        }

        return back()->with('success', 'Đã xác nhận phiếu nhập kho.');
    }

    public function cancel(StockEntry $stockEntry): RedirectResponse
    {
        try {
            $this->stockService->cancelEntry($stockEntry);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($stockEntry->purchase_order_id) {
            $po = PurchaseOrder::find($stockEntry->purchase_order_id);
            if ($po) $this->purchaseOrderService->syncReceiveStatus($po);
        }

        return back()->with('success', 'Đã hủy phiếu nhập kho.');
    }

    public function destroy(StockEntry $stockEntry): RedirectResponse
    {
        if ($stockEntry->status !== StockEntryStatus::Cancelled) {
            return back()->with('error', 'Chỉ có thể xóa phiếu đã hủy.');
        }

        $purchaseOrderId = $stockEntry->purchase_order_id;

        // Chặn nếu PO liên kết còn hóa đơn đầu vào chưa hủy
        if ($purchaseOrderId) {
            $activeInvoices = PurchaseInvoice::where('purchase_order_id', $purchaseOrderId)
                ->where('status', '!=', PurchaseInvoiceStatus::Cancelled->value)
                ->count();
            if ($activeInvoices > 0) {
                return back()->with('error',
                    "Không thể xóa: đơn mua hàng liên kết còn {$activeInvoices} hóa đơn đầu vào chưa hủy. Vui lòng hủy hóa đơn trước."
                );
            }
        }

        DB::transaction(function () use ($stockEntry) {
            $itemIds = $stockEntry->items()->pluck('id');
            ProductSerial::whereIn('stock_entry_item_id', $itemIds)->delete();

            StockMovement::where('source_type', StockEntry::class)
                ->where('source_id', $stockEntry->id)
                ->delete();

            // Xóa entry — stock_entry_items cascade tự xóa theo
            $stockEntry->delete();
        });

        if ($purchaseOrderId) {
            $po = PurchaseOrder::find($purchaseOrderId);
            if ($po) $this->purchaseOrderService->syncReceiveStatus($po);
        }

        return redirect()->route('warehouse.stock-entries.index')->with('success', 'Đã xóa phiếu nhập kho.');
    }
}
