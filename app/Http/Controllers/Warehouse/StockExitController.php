<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\ContractStatus;
use App\Enums\ItemUsageType;
use App\Enums\OrderStatus;
use App\Enums\SerialStatus;
use App\Enums\StockExitStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\StockEntryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\ProjectWipEntry;
use App\Models\StockExitItemLotAllocation;
use App\Models\StockExit;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\OrderService;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StockExitController extends Controller
{
    public function __construct(
        private StockService $stockService,
        private OrderService $orderService,
        private AccountingService $accounting,
    ) {}

    public function index(Request $request): Response
    {
        $q      = $request->input('q');
        $status = $request->input('status');

        return Inertia::render('Warehouse/StockExits/Index', [
            'exits' => StockExit::with(['warehouse', 'customer', 'creator'])
                ->withCount('items')
                ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                    $sq->where('code', 'ilike', "%{$q}%")
                       ->orWhere('reason', 'ilike', "%{$q}%")
                       ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%")
                                                              ->orWhere('code', 'ilike', "%{$q}%"))
                       ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'ilike', "%{$q}%"))
                       ->orWhereHas('creator', fn ($u) => $u->where('name', 'ilike', "%{$q}%"));
                }))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($e) => [
                    'id' => $e->id,
                    'code' => $e->code,
                    'exit_date' => $e->exit_date->format('d/m/Y'),
                    'status' => $e->status->value,
                    'status_label' => $e->status->label(),
                    'status_color' => $e->status->color(),
                    'warehouse' => $e->warehouse->name,
                    'customer' => $e->customer?->name,
                    'reason' => $e->reason,
                    'creator' => $e->creator->name,
                    'items_count' => $e->items_count,
                ]),
            'filters'  => ['q' => $q, 'status' => $status],
            'statuses' => collect(StockExitStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/StockExits/Form', [
            'nextCode'      => StockExit::generateCode(),
            'warehouses'    => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'serials'       => ProductSerial::where('status', SerialStatus::InStock)
                ->get(['id', 'product_id', 'warehouse_id', 'serial_number']),
            'orders'        => $this->pendingOrdersForDropdown(),
            'usageTypes'    => collect(ItemUsageType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
            'issuePurposes' => $this->issuePurposesForDropdown(),
        ]);
    }

    /**
     * API: lấy giá vốn AVCO (avg_cost) cho danh sách sản phẩm tại kho.
     * GET /warehouse/stock-exits-avco-costs?warehouse_id=X&product_ids[]=Y
     */
    public function avcoCosts(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id'  => ['required', 'exists:warehouses,id'],
            'product_ids'   => ['required', 'array'],
            'product_ids.*' => ['integer'],
        ]);

        $warehouseId = $request->integer('warehouse_id');
        $productIds  = $request->input('product_ids');

        $balances = InventoryBalance::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'avg_cost', 'qty_on_hand']);

        $result = $balances->mapWithKeys(fn ($b) => [
            $b->product_id => [
                'avg_cost'    => (float) $b->avg_cost,
                'qty_on_hand' => (float) $b->qty_on_hand,
            ],
        ]);

        // Với các sản phẩm chưa có AVCO, fallback lấy avg từ lịch sử nhập kho
        $foundIds   = $balances->pluck('product_id')->toArray();
        $missingIds = array_values(array_diff($productIds, $foundIds));

        if (! empty($missingIds)) {
            $entryAvg = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
                ->where('stock_entries.warehouse_id', $warehouseId)
                ->whereIn('stock_entry_items.product_id', $missingIds)
                ->where('stock_entries.status', 'confirmed')
                ->select('stock_entry_items.product_id')
                ->selectRaw(
                    'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost'
                )
                ->groupBy('stock_entry_items.product_id')
                ->get();

            foreach ($entryAvg as $row) {
                $result[$row->product_id] = [
                    'avg_cost'    => round((float) $row->avg_cost, 2),
                    'qty_on_hand' => null,
                ];
            }
        }

        return response()->json(['data' => $result]);
    }

    /**
     * API: lấy danh sách sản phẩm còn tồn theo project_inventory_lots.
     * GET /warehouse/stock-exits/available-lots?project_id=X&warehouse_id=Y
     */
    public function availableLots(Request $request): JsonResponse
    {
        $request->validate([
            'project_id'  => ['required', 'exists:projects,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
        ]);

        $projectId   = $request->integer('project_id');
        $warehouseId = $request->integer('warehouse_id');

        $lots = ProjectInventoryLot::with(['product', 'purchaseOrder', 'stockEntry'])
            ->where('project_id', $projectId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', 'active')
            ->whereRaw('issued_qty < received_qty')
            ->orderBy('received_at', 'asc')
            ->get();

        // Group by product
        $grouped = $lots->groupBy('product_id')->map(function ($productLots) {
            $first = $productLots->first();
            return [
                'product_id'         => $first->product_id,
                'product_code'       => $first->product?->code,
                'product_name'       => $first->product?->name,
                'unit'               => $first->product?->unit,
                'inventory_account'  => $first->product?->inventory_account,
                'total_received_qty' => $productLots->sum(fn($l) => (float)$l->received_qty),
                'total_issued_qty'   => $productLots->sum(fn($l) => (float)$l->issued_qty),
                'available_qty'      => $productLots->sum(fn($l) => (float)$l->received_qty - (float)$l->issued_qty),
                'lots'               => $productLots->map(fn($l) => [
                    'id'                   => $l->id,
                    'purchase_order_id'    => $l->purchase_order_id,
                    'purchase_order_code'  => $l->purchaseOrder?->code,
                    'stock_entry_id'       => $l->stock_entry_id,
                    'stock_entry_code'     => $l->stockEntry?->code,
                    'received_at'          => $l->received_at?->format('Y-m-d'),
                    'received_qty'         => (float) $l->received_qty,
                    'issued_qty'           => (float) $l->issued_qty,
                    'available_qty'        => (float) $l->received_qty - (float) $l->issued_qty,
                    'unit_cost'            => (float) $l->unit_cost,
                ])->values(),
            ];
        })->values();

        return response()->json(['lots' => $grouped]);
    }

    /**
     * Kiểm tra tồn kho trước khi lưu phiếu xuất (draft).
     * Non-project: dùng inventory_balances (chỉ kiểm tra nếu đã có AVCO).
     * Project: dùng project_inventory_lots.
     * Trả về mảng lỗi hoặc [] nếu không có lỗi.
     */
    private function checkStockAvailability(array $items, int $warehouseId, bool $isProject, ?int $projectId): array
    {
        // Gộp số lượng theo từng product_id
        $productQtyMap = [];
        foreach ($items as $item) {
            $pid = $item['product_id'];
            $productQtyMap[$pid] = ($productQtyMap[$pid] ?? 0) + (int) $item['quantity'];
        }

        $errors = [];
        $warehouse = Warehouse::find($warehouseId);

        if ($isProject && $projectId) {
            foreach ($productQtyMap as $productId => $requestedQty) {
                // Tồn FIFO từ project_inventory_lots của dự án
                $lotAvailable = (float) (ProjectInventoryLot::where('project_id', $projectId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where('status', 'active')
                    ->whereRaw('issued_qty < received_qty')
                    ->selectRaw('SUM(received_qty - issued_qty) as avail')
                    ->value('avail') ?? 0);

                // Nếu lots đủ thì OK
                if ($lotAvailable >= $requestedQty) continue;

                // Fallback: cộng thêm tồn AVCO (hàng kho công ty)
                $avcoAvailable = (float) (InventoryBalance::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->value('qty_on_hand') ?? 0);

                $totalAvailable = $lotAvailable + $avcoAvailable;
                if ($totalAvailable < $requestedQty) {
                    $product = Product::find($productId);
                    $errors[] = "Không đủ tồn kho cho {$product->code} - {$product->name} tại kho {$warehouse->name}. "
                        . "Tồn lô dự án: {$lotAvailable}, tồn kho chung: {$avcoAvailable}, số lượng xuất: {$requestedQty}.";
                }
            }
        } else {
            foreach ($productQtyMap as $productId => $requestedQty) {
                $balance = InventoryBalance::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->first();

                if ($balance && (float) $balance->qty_on_hand < $requestedQty) {
                    $product = Product::find($productId);

                    // Tìm kho khác đang có tồn để gợi ý đổi kho
                    $otherWarehouses = InventoryBalance::with('warehouse')
                        ->where('product_id', $productId)
                        ->where('warehouse_id', '!=', $warehouseId)
                        ->where('qty_on_hand', '>', 0)
                        ->orderByDesc('qty_on_hand')
                        ->get();

                    $totalAvailable = $otherWarehouses->sum('qty_on_hand') + (float) $balance->qty_on_hand;

                    $msg = "Không đủ tồn kho cho {$product->code} - {$product->name} tại kho {$warehouse->name}. "
                        . "Tồn tại kho đang chọn: {$balance->qty_on_hand}, số lượng xuất: {$requestedQty}.";

                    if ($otherWarehouses->isNotEmpty()) {
                        $warehouseList = $otherWarehouses
                            ->map(fn ($b) => "{$b->warehouse->name}: {$b->qty_on_hand}")
                            ->join(', ');
                        $msg .= " Tồn toàn hệ thống: {$totalAvailable}. Kho khác có hàng: {$warehouseList}.";
                    }

                    $errors[] = $msg;
                }
            }
        }

        return $errors;
    }

    private function issuePurposesForDropdown(): array
    {
        return [
            ['value' => 'project_cost',     'label' => 'Xuất dùng cho dự án (Nợ 154)'],
            ['value' => 'project_transfer', 'label' => 'Điều chuyển sang kho dự án (chuyển kho)'],
            ['value' => 'sale_delivery',    'label' => 'Xuất bán hàng (Nợ 632)'],
            ['value' => 'selling_expense',  'label' => 'Chi phí bán hàng (Nợ 6421)'],
            ['value' => 'admin_expense',    'label' => 'Chi phí QLDN (Nợ 6422)'],
            ['value' => 'internal_use',     'label' => 'Dùng nội bộ (cần cấu hình TK)'],
        ];
    }

    private function activeProjectsForDropdown(): array
    {
        return Project::whereNotIn('status', ['completed', 'cancelled'])
            ->orderByDesc('id')
            ->get(['id', 'code', 'name', 'customer_id'])
            ->map(fn ($p) => [
                'id'          => $p->id,
                'code'        => $p->code,
                'name'        => $p->name,
                'customer_id' => $p->customer_id,
            ])
            ->all();
    }

    private function pendingOrdersForDropdown(): array
    {
        $contractOrderIds = Contract::whereIn('status', [
            ContractStatus::Active->value,
            ContractStatus::Completed->value,
        ])->whereNotNull('order_id')->pluck('order_id')->flip()->toArray();

        return Order::with('items')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'id'           => $o->id,
                'code'         => $o->code,
                'customer_id'  => $o->customer_id,
                'status'       => $o->status->value,
                'status_label' => $o->status->label(),
                'has_contract' => isset($contractOrderIds[$o->id]),
                'items'        => $o->items
                    ->whereNotNull('product_id')
                    ->map(fn ($i) => [
                        'id'                 => $i->id,
                        'product_id'         => $i->product_id,
                        'product_name'       => $i->name,
                        'unit'               => $i->unit,
                        'unit_price'         => (float) $i->unit_price,
                        'quantity'           => (int) $i->quantity,
                        'delivered_quantity' => (int) $i->delivered_quantity,
                        'remaining'          => max(0, (int) $i->quantity - (int) $i->delivered_quantity),
                    ])
                    ->values(),
            ])
            ->all();
    }

    private function orderHasContract(?int $orderId): bool
    {
        if (! $orderId) {
            return true; // no order linked — no warning needed
        }

        return Contract::where('order_id', $orderId)
            ->whereIn('status', [ContractStatus::Active->value, ContractStatus::Completed->value])
            ->exists();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                    => ['required', 'string', 'unique:stock_exits,code'],
            'warehouse_id'            => ['required', 'exists:warehouses,id'],
            'customer_id'             => ['nullable', 'exists:customers,id'],
            'order_id'                => ['nullable', 'exists:orders,id'],
            'purchase_order_ids'      => ['nullable', 'array'],
            'purchase_order_ids.*'    => ['integer', 'exists:purchase_orders,id'],
            'item_usage_type'         => ['required', 'string', 'in:commercial,project'],
            'issue_purpose'           => ['nullable', 'string', 'in:project_cost,project_transfer,sale_delivery,selling_expense,admin_expense,internal_use'],
            'to_warehouse_id'         => ['nullable', 'exists:warehouses,id'],
            'cost_account'            => ['nullable', 'string', 'max:20'],
            'inventory_account'       => ['nullable', 'string', 'max:20'],
            'project_id'              => ['nullable', 'exists:projects,id'],
            'exit_date'               => ['required', 'date'],
            'reason'                  => ['nullable', 'string', 'max:255'],
            'notes'                   => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.product_id'      => ['required', 'exists:products,id'],
            'items.*.order_item_id'   => ['nullable', 'integer', 'exists:order_items,id'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'      => ['sometimes', 'nullable', 'array'],
            'items.*.serial_ids.*'    => ['integer', 'exists:product_serials,id'],
        ]);

        $purpose = $data['issue_purpose'] ?? null;

        // project_id bắt buộc khi issue_purpose=project_cost/project_transfer hoặc item_usage_type=project
        $requiresProject = in_array($purpose, ['project_cost', 'project_transfer']) || $data['item_usage_type'] === 'project';
        if ($requiresProject && empty($data['project_id'])) {
            return back()->withErrors(['project_id' => 'Vui lòng chọn dự án khi xuất hàng cho dự án.'])->withInput();
        }

        // to_warehouse_id bắt buộc khi project_transfer
        if ($purpose === 'project_transfer' && empty($data['to_warehouse_id'])) {
            return back()->withErrors(['to_warehouse_id' => 'Vui lòng chọn kho đích khi điều chuyển sang kho dự án.'])->withInput();
        }
        if ($purpose === 'project_transfer' && !empty($data['to_warehouse_id'])
            && (int) $data['to_warehouse_id'] === (int) $data['warehouse_id']) {
            return back()->withErrors(['to_warehouse_id' => 'Kho đích phải khác kho nguồn.'])->withInput();
        }

        // Validate serial (tùy chọn — không bắt buộc phải chọn đủ số lượng)
        $allSerialIds = [];
        foreach ($data['items'] as $index => $itemData) {
            $serialIds = $itemData['serial_ids'] ?? [];
            if (empty($serialIds)) continue;

            $duplicates = array_intersect($serialIds, $allSerialIds);
            if (! empty($duplicates)) {
                return back()->withErrors([
                    "items.{$index}.serial_ids" => 'Serial đã được chọn ở dòng khác: ' . implode(', ', $duplicates) . '.',
                ])->withInput();
            }
            $allSerialIds = array_merge($allSerialIds, $serialIds);

            $valid = ProductSerial::whereIn('id', $serialIds)
                ->where('product_id', $itemData['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('status', SerialStatus::InStock)
                ->count();

            if ($valid !== count($serialIds)) {
                return back()->withErrors([
                    "items.{$index}.serial_ids" => 'Một số serial không hợp lệ hoặc đã xuất kho.',
                ])->withInput();
            }
        }

        // Kiểm tra tồn kho (cảnh báo sớm trước khi confirm)
        $isProjectExit = in_array($purpose, ['project_cost', 'project_transfer']) || $data['item_usage_type'] === 'project';
        $stockErrors = $this->checkStockAvailability(
            $data['items'],
            (int) $data['warehouse_id'],
            $isProjectExit,
            isset($data['project_id']) ? (int) $data['project_id'] : null
        );
        if (! empty($stockErrors)) {
            return back()->withErrors(['items' => implode(' | ', $stockErrors)])->withInput();
        }

        $poIds = $purpose === 'project_cost' ? array_values(array_unique(array_filter($data['purchase_order_ids'] ?? []))) : [];

        $exit = StockExit::create([
            'code'              => $data['code'],
            'warehouse_id'      => $data['warehouse_id'],
            'to_warehouse_id'   => $purpose === 'project_transfer' ? ($data['to_warehouse_id'] ?? null) : null,
            'customer_id'       => $data['customer_id'] ?? null,
            'order_id'          => $data['order_id'] ?? null,
            'purchase_order_id' => $poIds[0] ?? null,
            'item_usage_type'   => $data['item_usage_type'],
            'issue_purpose'     => $data['issue_purpose'] ?? null,
            'cost_account'      => $data['cost_account'] ?? null,
            'inventory_account' => $data['inventory_account'] ?? null,
            'project_id'        => $requiresProject ? ($data['project_id'] ?? null) : ($data['project_id'] ?? null),
            'created_by'        => auth()->id(),
            'exit_date'         => $data['exit_date'],
            'reason'            => $data['reason'] ?? null,
            'notes'             => $data['notes'] ?? null,
        ]);

        if ($poIds) {
            $exit->purchaseOrders()->attach($poIds);
        }

        foreach ($data['items'] as $itemData) {
            $exitItem = $exit->items()->create([
                'product_id'    => $itemData['product_id'],
                'order_item_id' => $purpose === 'sale_delivery' ? ($itemData['order_item_id'] ?? null) : null,
                'quantity'      => $itemData['quantity'],
                'unit_price'    => $itemData['unit_price'],
            ]);

            if (! empty($itemData['serial_ids'])) {
                ProductSerial::whereIn('id', $itemData['serial_ids'])
                    ->update(['stock_exit_item_id' => $exitItem->id]);
            }
        }

        return redirect()->route('warehouse.stock-exits.show', $exit)
            ->with('success', 'Đã tạo phiếu xuất kho.');
    }

    public function show(StockExit $stockExit): Response
    {
        $stockExit->load(['warehouse', 'customer', 'order', 'purchaseOrders', 'creator', 'project', 'items.product', 'items.serials']);

        return Inertia::render('Warehouse/StockExits/Show', [
            'hasOrderContract' => $this->orderHasContract($stockExit->order_id),
            'exit' => [
                'id'           => $stockExit->id,
                'code'         => $stockExit->code,
                'exit_date'    => $stockExit->exit_date->format('d/m/Y'),
                'status'       => $stockExit->status->value,
                'status_label' => $stockExit->status->label(),
                'status_color' => $stockExit->status->color(),
                'warehouse'    => ['name' => $stockExit->warehouse->name],
                'customer'     => $stockExit->customer ? ['name' => $stockExit->customer->name] : null,
                'order'         => $stockExit->order ? [
                    'id'           => $stockExit->order->id,
                    'code'         => $stockExit->order->code,
                    'status_label' => $stockExit->order->status->label(),
                    'status_color' => $stockExit->order->status->color(),
                ] : null,
                'purchase_orders' => $stockExit->purchaseOrders->map(fn ($po) => [
                    'id'   => $po->id,
                    'code' => $po->code,
                ])->values()->all(),
                'item_usage_type'       => $stockExit->item_usage_type?->value ?? 'commercial',
                'item_usage_type_label' => $stockExit->item_usage_type?->label() ?? 'Bán thương mại',
                'project'      => $stockExit->project ? [
                    'id'   => $stockExit->project->id,
                    'code' => $stockExit->project->code,
                    'name' => $stockExit->project->name,
                ] : null,
                'reason'       => $stockExit->reason,
                'creator'      => ['name' => $stockExit->creator->name],
                'notes'        => $stockExit->notes,
                'items'        => $stockExit->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_code' => $item->product->code,
                    'product_name' => $item->product->name,
                    'unit'         => $item->product->unit,
                    'quantity'     => $item->quantity,
                    'unit_price'   => $item->unit_price,
                    'source_cost'  => $item->source_cost !== null ? (float) $item->source_cost : null,
                    'total_cost'   => $item->total_cost !== null ? (float) $item->total_cost : null,
                    'cost_source'  => $item->cost_source,
                    'total'        => $item->total_cost !== null && (float) $item->total_cost > 0
                                        ? (float) $item->total_cost
                                        : (float) $item->quantity * (float) $item->unit_price,
                    'serials'      => $item->serials->map(fn ($s) => [
                        'id'           => $s->id,
                        'serial_number' => $s->serial_number,
                        'status'       => $s->status->value,
                        'status_label' => $s->status->label(),
                        'status_color' => $s->status->color(),
                    ]),
                ]),
            ],
        ]);
    }

    public function edit(StockExit $stockExit): Response|RedirectResponse
    {
        if ($stockExit->status !== StockExitStatus::Draft) {
            return redirect()->route('warehouse.stock-exits.show', $stockExit)
                ->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $stockExit->load(['customer', 'project', 'purchaseOrders', 'items.product', 'items.serials']);

        return Inertia::render('Warehouse/StockExits/Form', [
            'exit'       => [
                'id'                   => $stockExit->id,
                'code'                 => $stockExit->code,
                'exit_date'            => $stockExit->exit_date->format('Y-m-d'),
                'warehouse_id'         => $stockExit->warehouse_id,
                'to_warehouse_id'      => $stockExit->to_warehouse_id,
                'customer_id'          => $stockExit->customer_id,
                'customer_name'        => $stockExit->customer?->name ?? '',
                'customer_code'        => $stockExit->customer?->code ?? '',
                'order_id'             => $stockExit->order_id,
                'purchase_order_ids'   => $stockExit->purchaseOrders->pluck('id')->toArray(),
                'item_usage_type'      => $stockExit->item_usage_type?->value ?? 'commercial',
                'project_id'           => $stockExit->project_id,
                'project_name'         => $stockExit->project?->name ?? '',
                'project_code'         => $stockExit->project?->code ?? '',
                'issue_purpose'        => $stockExit->issue_purpose,
                'reason'               => $stockExit->reason,
                'notes'                => $stockExit->notes,
                'items'           => $stockExit->items->map(fn ($item) => [
                    'product_id'      => $item->product_id,
                    'order_item_id'   => $item->order_item_id,
                    'product_name'    => $item->product?->name ?? '',
                    'product_code'    => $item->product?->code ?? '',
                    'product_unit'    => $item->product?->unit ?? '',
                    'quantity'        => $item->quantity,
                    'unit_price'      => (float) $item->unit_price,
                    'serial_ids'      => $item->serials->pluck('id')->toArray(),
                ])->values(),
            ],
            'warehouses'    => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'serials'       => ProductSerial::where('status', SerialStatus::InStock)
                ->get(['id', 'product_id', 'warehouse_id', 'serial_number']),
            'orders'        => $this->pendingOrdersForDropdown(),
            'usageTypes'    => collect(ItemUsageType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
            'issuePurposes' => $this->issuePurposesForDropdown(),
        ]);
    }

    public function update(Request $request, StockExit $stockExit): RedirectResponse
    {
        if ($stockExit->status !== StockExitStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'code'                    => ['required', 'string', Rule::unique('stock_exits', 'code')->ignore($stockExit->id)],
            'warehouse_id'            => ['required', 'exists:warehouses,id'],
            'customer_id'             => ['nullable', 'exists:customers,id'],
            'order_id'                => ['nullable', 'exists:orders,id'],
            'purchase_order_ids'      => ['nullable', 'array'],
            'purchase_order_ids.*'    => ['integer', 'exists:purchase_orders,id'],
            'item_usage_type'         => ['required', 'string', 'in:commercial,project'],
            'issue_purpose'           => ['nullable', 'string', 'in:project_cost,project_transfer,sale_delivery,selling_expense,admin_expense,internal_use'],
            'to_warehouse_id'         => ['nullable', 'exists:warehouses,id'],
            'project_id'              => ['nullable', 'exists:projects,id'],
            'exit_date'               => ['required', 'date'],
            'reason'                  => ['nullable', 'string', 'max:255'],
            'notes'                   => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.product_id'      => ['required', 'exists:products,id'],
            'items.*.order_item_id'   => ['nullable', 'integer', 'exists:order_items,id'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'      => ['sometimes', 'nullable', 'array'],
            'items.*.serial_ids.*'    => ['integer', 'exists:product_serials,id'],
        ]);

        $purpose = $data['issue_purpose'] ?? null;
        $requiresProject = in_array($purpose, ['project_cost', 'project_transfer']) || $data['item_usage_type'] === 'project';
        if ($requiresProject && empty($data['project_id'])) {
            return back()->withErrors(['project_id' => 'Vui lòng chọn dự án khi xuất hàng cho dự án.'])->withInput();
        }
        if ($purpose === 'project_transfer' && empty($data['to_warehouse_id'])) {
            return back()->withErrors(['to_warehouse_id' => 'Vui lòng chọn kho đích khi điều chuyển sang kho dự án.'])->withInput();
        }
        if ($purpose === 'project_transfer' && !empty($data['to_warehouse_id'])
            && (int) $data['to_warehouse_id'] === (int) $data['warehouse_id']) {
            return back()->withErrors(['to_warehouse_id' => 'Kho đích phải khác kho nguồn.'])->withInput();
        }

        $allSerialIds = [];
        foreach ($data['items'] as $index => $itemData) {
            $serialIds = $itemData['serial_ids'] ?? [];
            if (empty($serialIds)) continue;

            $duplicates = array_intersect($serialIds, $allSerialIds);
            if (! empty($duplicates)) {
                return back()->withErrors([
                    "items.{$index}.serial_ids" => 'Serial đã được chọn ở dòng khác: ' . implode(', ', $duplicates) . '.',
                ])->withInput();
            }
            $allSerialIds = array_merge($allSerialIds, $serialIds);

            $valid = ProductSerial::whereIn('id', $serialIds)
                ->where('product_id', $itemData['product_id'])
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('status', SerialStatus::InStock)
                ->count();

            if ($valid !== count($serialIds)) {
                return back()->withErrors([
                    "items.{$index}.serial_ids" => 'Một số serial không hợp lệ hoặc đã xuất kho.',
                ])->withInput();
            }
        }

        // Kiểm tra tồn kho
        $isProjectExit = in_array($purpose, ['project_cost', 'project_transfer']) || $data['item_usage_type'] === 'project';
        $stockErrors = $this->checkStockAvailability(
            $data['items'],
            (int) $data['warehouse_id'],
            $isProjectExit,
            isset($data['project_id']) ? (int) $data['project_id'] : null
        );
        if (! empty($stockErrors)) {
            return back()->withErrors(['items' => implode(' | ', $stockErrors)])->withInput();
        }

        DB::transaction(function () use ($data, $stockExit, $purpose) {
            $oldItemIds = $stockExit->items->pluck('id');
            ProductSerial::whereIn('stock_exit_item_id', $oldItemIds)
                ->update(['stock_exit_item_id' => null]);
            StockExitItemLotAllocation::whereIn('stock_exit_item_id', $oldItemIds)->delete();

            $stockExit->items()->delete();

            $isProject = in_array($purpose, ['project_cost', 'project_transfer']) || $data['item_usage_type'] === 'project';
            $poIds     = $purpose === 'project_cost' ? array_values(array_unique(array_filter($data['purchase_order_ids'] ?? []))) : [];

            $stockExit->update([
                'code'              => $data['code'],
                'warehouse_id'      => $data['warehouse_id'],
                'to_warehouse_id'   => $purpose === 'project_transfer' ? ($data['to_warehouse_id'] ?? null) : null,
                'customer_id'       => $data['customer_id'] ?? null,
                'order_id'          => $data['order_id'] ?? null,
                'purchase_order_id' => $poIds[0] ?? null,
                'item_usage_type'   => $data['item_usage_type'],
                'issue_purpose'     => $purpose,
                'project_id'        => $isProject ? ($data['project_id'] ?? null) : null,
                'exit_date'         => $data['exit_date'],
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
            ]);

            $stockExit->purchaseOrders()->sync($poIds);

            foreach ($data['items'] as $itemData) {
                $exitItem = $stockExit->items()->create([
                    'product_id'    => $itemData['product_id'],
                    'order_item_id' => $purpose === 'sale_delivery' ? ($itemData['order_item_id'] ?? null) : null,
                    'quantity'      => $itemData['quantity'],
                    'unit_price'    => $itemData['unit_price'],
                ]);

                if (! empty($itemData['serial_ids'])) {
                    ProductSerial::whereIn('id', $itemData['serial_ids'])
                        ->update(['stock_exit_item_id' => $exitItem->id]);
                }
            }
        });

        return redirect()->route('warehouse.stock-exits.show', $stockExit)
            ->with('success', 'Đã cập nhật phiếu xuất kho.');
    }

    public function pdf(StockExit $stockExit)
    {
        $stockExit->load(['warehouse', 'customer', 'order', 'creator', 'items.product', 'items.serials']);
        $pdf = Pdf::loadView('pdf.stock_exit', compact('stockExit'))->setPaper('a4', 'portrait');
        return $pdf->stream("PhieuXuatKho-{$stockExit->code}.pdf");
    }

    public function confirm(StockExit $stockExit): RedirectResponse
    {
        try {
            $this->stockService->confirmExit($stockExit);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $warnings = $this->orderService->syncDelivery($stockExit);

        if ($warnings) {
            $warningMsg = 'Đã xác nhận xuất kho. Cảnh báo: ' . implode(' | ', $warnings);
            return back()->with('warning', $warningMsg);
        }

        return back()->with('success', 'Đã xác nhận phiếu xuất kho.');
    }

    public function cancel(StockExit $stockExit): RedirectResponse
    {
        try {
            $this->stockService->cancelExit($stockExit);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy phiếu xuất kho.');
    }

    public function destroy(StockExit $stockExit): RedirectResponse
    {
        $isAdmin = auth()->user()->hasRole('admin');

        if (! $isAdmin && ! in_array($stockExit->status, [StockExitStatus::Draft, StockExitStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa phiếu ở trạng thái nháp hoặc đã hủy.');
        }

        // Admin force-delete phiếu Confirmed: log snapshot đầy đủ, sau đó cancel trước
        if ($stockExit->status === StockExitStatus::Confirmed) {
            $stockExit->load('items');
            activity('admin_force_delete')
                ->performedOn($stockExit)
                ->causedBy(auth()->user())
                ->withProperties([
                    'code'        => $stockExit->code,
                    'status'      => $stockExit->status->value,
                    'exit_date'   => (string) $stockExit->exit_date,
                    'warehouse_id' => $stockExit->warehouse_id,
                    'project_id'  => $stockExit->project_id,
                    'order_id'    => $stockExit->order_id,
                    'items'       => $stockExit->items->map(fn ($i) => [
                        'product_id' => $i->product_id,
                        'quantity'   => $i->quantity,
                    ])->toArray(),
                ])
                ->log("Admin xóa cưỡng chế phiếu xuất kho {$stockExit->code}");

            try {
                $this->stockService->cancelExit($stockExit, adminForce: true);
            } catch (\Exception $e) {
                return back()->with('error', 'Không thể hủy phiếu trước khi xóa: ' . $e->getMessage());
            }
        }

        // Đảo journal chưa reversed (e.g. sau cancel nhưng reversal thất bại)
        $postedJournal = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $stockExit->id)
            ->where('status', 'posted')
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();
        if ($postedJournal) {
            try {
                $this->accounting->reverse($postedJournal, "Dọn dẹp: xóa phiếu xuất kho {$stockExit->code}");
            } catch (\Exception $e) {
                \Log::warning("Cannot reverse journal on exit destroy [{$stockExit->code}]: " . $e->getMessage());
            }
        }

        DB::transaction(function () use ($stockExit) {
            $itemIds = $stockExit->items->pluck('id');
            ProductSerial::whereIn('stock_exit_item_id', $itemIds)
                ->update(['stock_exit_item_id' => null]);
            ProjectWipEntry::where('source_type', StockExit::class)
                ->where('source_id', $stockExit->id)
                ->delete();
            StockExitItemLotAllocation::whereIn('stock_exit_item_id', $itemIds)->delete();
            $stockExit->items()->delete();

            // Void tất cả movements còn active trước khi xóa exit để tránh orphan movements
            // (cancelExit tạo reversal movements mới chứ không void originals, nên cả hai nhóm phải được void ở đây)
            StockMovement::where('source_type', StockExit::class)
                ->where('source_id', $stockExit->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'voided',
                    'notes'  => DB::raw("COALESCE(notes, '') || ' [voided: parent exit deleted]'"),
                ]);

            $stockExit->delete();
        });

        return redirect()->route('warehouse.stock-exits.index')
            ->with('success', 'Đã xóa phiếu xuất kho.');
    }
}
