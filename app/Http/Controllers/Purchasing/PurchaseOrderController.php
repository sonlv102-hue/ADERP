<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderInvoiceType;
use App\Enums\PurchaseOrderStatus;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\PurchaseOrderImport;
use App\Models\Order;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Purchasing/PurchaseOrders/Index', $this->ordersListProps($request));
    }

    public function importTemplate()
    {
        $headers = [
            'order_code', 'order_date', 'expected_date', 'supplier_code',
            'warehouse', 'product_code', 'quantity', 'unit_price', 'vat_rate',
            'subtotal', 'tax_amount', 'total', 'notes',
        ];

        $sampleRows = [
            ['[Hướng dẫn] Bắt buộc: order_code, order_date, supplier_code, warehouse, product_code, quantity, unit_price. Xóa 3 dòng này trước khi import.'],
            ['Cùng order_code trên nhiều dòng = nhiều sản phẩm trong 1 đơn. supplier_code = Mã NCC, warehouse = Tên kho.'],
            ['subtotal/tax_amount/total chỉ tham khảo — hệ thống tự tính lại từ quantity × unit_price × vat_rate.'],
            ['MH-2026-001', '2026-06-01', '2026-06-15', 'NCC-0001', 'Kho chính', 'SP-0001', 2, 5000000, 10, 10000000, 1000000, 11000000, 'Đơn mua tháng 6'],
            ['MH-2026-001', '',           '',            '',          '',           'SP-0002', 1, 3000000,  8,  3000000,  240000,  3240000, ''],
            ['MH-2026-002', '2026-06-05', '',            'NCC-0002', 'Kho HCM',    'SP-0003', 5, 1000000,  0,  5000000,       0,  5000000, ''],
        ];

        return Excel::download(new TemplateExport($headers, 'Đơn mua hàng', $sampleRows), 'purchase-order-template.xlsx');
    }

    public function importPreview(Request $request): Response
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
        ]);

        $suppliers    = Supplier::where('is_active', true)->get(['id', 'code', 'name']);
        $warehouses   = Warehouse::where('is_active', true)->get(['id', 'name']);
        $products     = Product::where('is_active', true)->get(['id', 'code', 'name', 'unit']);
        $existingCodes= PurchaseOrder::pluck('code')->toArray();

        $import = new PurchaseOrderImport($suppliers, $warehouses, $products, $existingCodes);
        Excel::import($import, $request->file('file'));

        $validOrders = collect($import->parsedOrders)
            ->filter(fn ($o) => !isset($o['_invalid']) && !empty($o['items']))
            ->values()
            ->toArray();

        session(['po_import' => $validOrders]);

        return Inertia::render('Purchasing/PurchaseOrders/Index', array_merge(
            $this->ordersListProps(),
            [
                'preview' => [
                    'total_rows'     => $import->totalRows,
                    'valid_orders'   => count($validOrders),
                    'error_count'    => count($import->errors),
                    'warning_count'  => count($import->warnings),
                    'orders'         => $validOrders,
                    'errors'         => $import->errors,
                    'warnings'       => $import->warnings,
                    'has_duplicates' => collect($validOrders)->contains('exists_in_db', true),
                ],
            ]
        ));
    }

    public function importConfirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'duplicate_action' => ['required', Rule::in(['skip', 'update', 'abort'])],
        ]);

        $validOrders = session('po_import', []);
        if (empty($validOrders)) {
            return back()->with('error', 'Phiên import đã hết hạn. Vui lòng upload lại file.');
        }

        $dupAction    = $data['duplicate_action'];
        $existingMap  = PurchaseOrder::whereIn('code', array_column($validOrders, 'code'))
            ->pluck('id', 'code')
            ->toArray();

        if ($dupAction === 'abort' && !empty($existingMap)) {
            session()->forget('po_import');
            return back()->with('error', 'Import bị hủy: ' . count($existingMap) . ' mã đơn đã tồn tại: ' . implode(', ', array_keys($existingMap)));
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($validOrders, $existingMap, $dupAction, &$created, &$updated, &$skipped) {
            foreach ($validOrders as $orderData) {
                $code       = $orderData['code'];
                $existingId = $existingMap[$code] ?? null;

                if ($existingId) {
                    if ($dupAction === 'skip') { $skipped++; continue; }

                    $po = PurchaseOrder::find($existingId);
                    if ($po->status !== PurchaseOrderStatus::Draft) { $skipped++; continue; }

                    $po->update([
                        'supplier_id'   => $orderData['supplier_id'],
                        'warehouse_id'  => $orderData['warehouse_id'],
                        'order_date'    => $orderData['order_date'],
                        'expected_date' => $orderData['expected_date'],
                        'notes'         => $orderData['notes'],
                    ]);
                    $po->items()->delete();
                    $updated++;
                } else {
                    $po = PurchaseOrder::create([
                        'code'          => $code,
                        'supplier_id'   => $orderData['supplier_id'],
                        'warehouse_id'  => $orderData['warehouse_id'],
                        'order_date'    => $orderData['order_date'],
                        'expected_date' => $orderData['expected_date'],
                        'notes'         => $orderData['notes'],
                        'created_by'    => auth()->id(),
                    ]);
                    $created++;
                }

                foreach ($orderData['items'] as $item) {
                    $po->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity'   => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'vat_rate'   => $item['vat_rate'],
                    ]);
                }
            }
        });

        session()->forget('po_import');

        activity()
            ->causedBy(auth()->user())
            ->withProperties(['created' => $created, 'updated' => $updated, 'skipped' => $skipped])
            ->log("Import đơn mua hàng: tạo {$created}, cập nhật {$updated}, bỏ qua {$skipped}.");

        $msg = "Import thành công: tạo {$created} đơn mua";
        if ($updated) $msg .= ", cập nhật {$updated}";
        if ($skipped) $msg .= ", bỏ qua {$skipped}";
        $msg .= '.';

        return redirect()->route('purchasing.purchase-orders.index')->with('success', $msg);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Purchasing/PurchaseOrders/Form', [
            'nextCode'       => PurchaseOrder::generateCode(),
            'suppliers'      => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses'     => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products'       => Product::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'cost_price', 'vat_percent']),
            'projects'       => Project::whereIn('status', ['planning', 'in_progress'])->orderByDesc('id')->get(['id', 'code', 'name']),
            'orders'         => Order::whereNotIn('status', ['cancelled'])->with('customer')->orderByDesc('id')->get()->map(fn ($o) => [
                'id'   => $o->id,
                'code' => $o->code,
                'label'=> $o->code . ' — ' . $o->customer->name,
            ]),
            'prefillOrderId' => $request->query('order_id') ? (int) $request->query('order_id') : null,
            'invoiceTypes'   => collect(PurchaseOrderInvoiceType::cases())->map(fn ($e) => [
                'value' => $e->value, 'label' => $e->label(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'          => ['required', 'string', 'unique:purchase_orders,code'],
            'supplier_id'   => ['required', 'exists:suppliers,id'],
            'warehouse_id'  => ['required', 'exists:warehouses,id'],
            'project_id'    => ['nullable', 'exists:projects,id'],
            'order_id'      => ['nullable', 'exists:orders,id'],
            'order_date'    => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes'         => ['nullable', 'string'],
            'invoice_type'  => ['nullable', Rule::in(array_column(PurchaseOrderInvoiceType::cases(), 'value'))],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $po = PurchaseOrder::create([
            'code'          => $data['code'],
            'supplier_id'   => $data['supplier_id'],
            'warehouse_id'  => $data['warehouse_id'],
            'project_id'    => $data['project_id'] ?? null,
            'order_id'      => $data['order_id'] ?? null,
            'created_by'    => auth()->id(),
            'order_date'    => $data['order_date'],
            'expected_date' => $data['expected_date'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'invoice_type'  => $data['invoice_type'] ?? PurchaseOrderInvoiceType::Vat->value,
        ]);

        foreach ($data['items'] as $item) {
            $po->items()->create($item);
        }

        return redirect()->route('purchasing.purchase-orders.show', $po)
            ->with('success', 'Đã tạo đơn mua hàng.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'supplier', 'warehouse', 'creator', 'project', 'order.customer',
            'items.product' => fn ($q) => $q->withTrashed(),
            'stockEntries', 'purchaseInvoices',
        ]);

        return Inertia::render('Purchasing/PurchaseOrders/Show', [
            'order' => [
                'id'            => $purchaseOrder->id,
                'code'          => $purchaseOrder->code,
                'order_date'    => $purchaseOrder->order_date->format('d/m/Y'),
                'expected_date' => $purchaseOrder->expected_date?->format('d/m/Y'),
                'status'        => $purchaseOrder->status->value,
                'status_label'  => $purchaseOrder->status->label(),
                'status_color'  => $purchaseOrder->status->color(),
                'supplier'           => $purchaseOrder->supplier->name,
                'supplier_id'        => $purchaseOrder->supplier_id,
                'warehouse'          => $purchaseOrder->warehouse->name,
                'creator'            => $purchaseOrder->creator->name,
                'notes'              => $purchaseOrder->notes,
                'invoice_type'       => $purchaseOrder->invoice_type->value,
                'invoice_type_label' => $purchaseOrder->invoice_type->label(),
                'invoice_type_color' => $purchaseOrder->invoice_type->color(),
                'project'            => $purchaseOrder->project ? [
                    'id'   => $purchaseOrder->project->id,
                    'code' => $purchaseOrder->project->code,
                    'name' => $purchaseOrder->project->name,
                ] : null,
                'linked_order'       => $purchaseOrder->order ? [
                    'id'            => $purchaseOrder->order->id,
                    'code'          => $purchaseOrder->order->code,
                    'customer_name' => $purchaseOrder->order->customer->name,
                ] : null,
                'items'         => $purchaseOrder->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_code' => $item->product?->code ?? '—',
                    'product_name' => $item->product?->name ?? '(đã xóa)',
                    'unit'         => $item->product?->unit ?? '',
                    'quantity'     => $item->quantity,
                    'unit_price'   => $item->unit_price,
                    'vat_rate'     => $item->vat_rate,
                    'total'        => $item->quantity * $item->unit_price,
                    'vat_amount'   => $item->quantity * $item->unit_price * (float)($item->vat_rate ?? 0) / 100,
                ]),
                'stock_entries' => $purchaseOrder->stockEntries->map(fn ($e) => [
                    'id'   => $e->id,
                    'code' => $e->code,
                ]),
                'purchase_invoices' => $purchaseOrder->purchaseInvoices->map(fn ($inv) => [
                    'id'             => $inv->id,
                    'code'           => $inv->code,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date'   => $inv->invoice_date?->format('d/m/Y'),
                    'due_date'       => $inv->due_date?->format('d/m/Y'),
                    'total'          => $inv->total,
                    'paid_amount'    => $inv->paid_amount,
                    'remaining'      => $inv->remaining,
                    'status'         => $inv->status->value,
                    'status_label'   => $inv->status->label(),
                    'status_color'   => $inv->status->color(),
                ]),
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return redirect()->route('purchasing.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Chỉ có thể sửa đơn ở trạng thái nháp.');
        }

        $purchaseOrder->load('items');

        return Inertia::render('Purchasing/PurchaseOrders/Form', [
            'purchaseOrder' => [
                'id'            => $purchaseOrder->id,
                'code'          => $purchaseOrder->code,
                'supplier_id'   => $purchaseOrder->supplier_id,
                'warehouse_id'  => $purchaseOrder->warehouse_id,
                'project_id'    => $purchaseOrder->project_id,
                'order_id'      => $purchaseOrder->order_id,
                'order_date'    => $purchaseOrder->order_date->format('Y-m-d'),
                'expected_date' => $purchaseOrder->expected_date?->format('Y-m-d'),
                'notes'         => $purchaseOrder->notes,
                'invoice_type'  => $purchaseOrder->invoice_type->value,
                'items'         => $purchaseOrder->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'vat_rate'   => $item->vat_rate !== null ? (float) $item->vat_rate : null,
                ])->values(),
            ],
            'suppliers'      => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses'     => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products'       => Product::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'cost_price', 'vat_percent']),
            'projects'       => Project::whereIn('status', ['planning', 'in_progress'])->orderByDesc('id')->get(['id', 'code', 'name']),
            'orders'         => Order::whereNotIn('status', ['cancelled'])->with('customer')->orderByDesc('id')->get()->map(fn ($o) => [
                'id'   => $o->id,
                'code' => $o->code,
                'label'=> $o->code . ' — ' . $o->customer->name,
            ]),
            'prefillOrderId' => null,
            'invoiceTypes'   => collect(PurchaseOrderInvoiceType::cases())->map(fn ($e) => [
                'value' => $e->value, 'label' => $e->label(),
            ]),
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa đơn ở trạng thái nháp.');
        }

        $data = $request->validate([
            'supplier_id'   => ['required', 'exists:suppliers,id'],
            'warehouse_id'  => ['required', 'exists:warehouses,id'],
            'project_id'    => ['nullable', 'exists:projects,id'],
            'order_id'      => ['nullable', 'exists:orders,id'],
            'order_date'    => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes'         => ['nullable', 'string'],
            'invoice_type'  => ['nullable', Rule::in(array_column(PurchaseOrderInvoiceType::cases(), 'value'))],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $purchaseOrder->update([
            'supplier_id'   => $data['supplier_id'],
            'warehouse_id'  => $data['warehouse_id'],
            'project_id'    => $data['project_id'] ?? null,
            'order_id'      => $data['order_id'] ?? null,
            'order_date'    => $data['order_date'],
            'expected_date' => $data['expected_date'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'invoice_type'  => $data['invoice_type'] ?? $purchaseOrder->invoice_type->value,
        ]);

        $purchaseOrder->items()->delete();
        foreach ($data['items'] as $item) {
            $purchaseOrder->items()->create($item);
        }

        return redirect()->route('purchasing.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Đã cập nhật đơn mua hàng.');
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->service->send($purchaseOrder);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã gửi đơn mua hàng đến nhà cung cấp.');
    }

    public function receive(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (!in_array($purchaseOrder->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartialReceived])) {
            return back()->with('error', 'Đơn mua hàng không ở trạng thái hợp lệ để nhận hàng.');
        }

        return redirect()->route('warehouse.stock-entries.create', ['purchase_order_id' => $purchaseOrder->id]);
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->service->cancel($purchaseOrder);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy đơn mua hàng.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(auth()->user()->can('admin.users'), 403);

        if ($purchaseOrder->status !== PurchaseOrderStatus::Cancelled) {
            return back()->with('error', 'Chỉ xóa được đơn ở trạng thái Đã hủy.');
        }

        $code = $purchaseOrder->code;

        DB::transaction(function () use ($purchaseOrder) {
            // Payments cascade when invoices are deleted
            $purchaseOrder->purchaseInvoices()->delete();
            // Items cascade via FK, but explicit is clearer
            $purchaseOrder->items()->delete();
            // Clean up polymorphic document relations
            \App\Models\DocumentRelation::where('related_type', 'purchase_order')
                ->where('related_id', $purchaseOrder->id)
                ->delete();
            $purchaseOrder->delete();
        });

        return redirect()->route('purchasing.purchase-orders.index')
            ->with('success', "Đã xóa đơn mua hàng {$code}.");
    }

    private function ordersListProps(?Request $request = null): array
    {
        $q      = $request?->input('q');
        $status = $request?->input('status');

        return [
            'orders' => PurchaseOrder::with(['supplier', 'warehouse', 'creator'])
                ->withCount('items')
                ->addSelect([
                    'items_total' => PurchaseOrderItem::selectRaw('COALESCE(SUM(quantity * unit_price * (1 + COALESCE(vat_rate, 0) / 100.0)), 0)')
                        ->whereColumn('purchase_order_id', 'purchase_orders.id'),
                    'invoice_status' => \App\Models\PurchaseInvoice::select('status')
                        ->whereColumn('purchase_order_id', 'purchase_orders.id')
                        ->where('status', '!=', 'cancelled')
                        ->orderByDesc('id')
                        ->limit(1),
                ])
                ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                    $sq->where('code', 'ilike', "%{$q}%")
                       ->orWhere('notes', 'ilike', "%{$q}%")
                       ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$q}%")
                                                              ->orWhere('code', 'ilike', "%{$q}%"))
                       ->orWhereHas('creator', fn ($u) => $u->where('name', 'ilike', "%{$q}%"));
                }))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($po) => [
                    'id'                 => $po->id,
                    'code'               => $po->code,
                    'order_date'         => $po->order_date->format('d/m/Y'),
                    'expected_date'      => $po->expected_date?->format('d/m/Y'),
                    'status'             => $po->status->value,
                    'status_label'       => $po->status->label(),
                    'status_color'       => $po->status->color(),
                    'supplier'           => $po->supplier->name,
                    'warehouse'          => $po->warehouse->name,
                    'creator'            => $po->creator->name,
                    'items_count'        => $po->items_count,
                    'total'              => (float) $po->items_total,
                    'receipt_status'     => $this->resolveReceiptStatus($po->status->value),
                    'invoice_status'     => $po->invoice_status,
                    'invoice_type'       => $po->invoice_type->value,
                    'invoice_type_label' => $po->invoice_type->label(),
                    'invoice_type_color' => $po->invoice_type->color(),
                ]),
            'filters'  => ['q' => $q, 'status' => $status],
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ];
    }

    private function resolveReceiptStatus(string $status): string
    {
        return match($status) {
            'received'         => 'done',
            'partial_received' => 'partial',
            'cancelled'        => 'cancelled',
            default            => 'none',
        };
    }
}
