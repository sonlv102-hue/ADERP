<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\ContractStatus;
use App\Enums\ItemUsageType;
use App\Enums\OrderStatus;
use App\Enums\SerialStatus;
use App\Enums\StockExitStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Project;
use App\Models\StockExit;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\OrderService;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'customers'     => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'products'      => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'sell_price', 'has_serial']),
            'serials'       => ProductSerial::where('status', SerialStatus::InStock)
                ->get(['id', 'product_id', 'warehouse_id', 'serial_number']),
            'orders'        => $this->pendingOrdersForDropdown(),
            'projects'      => $this->activeProjectsForDropdown(),
            'usageTypes'    => collect(ItemUsageType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
        ]);
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
                        'product_id'         => $i->product_id,
                        'product_name'       => $i->name,
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
            'item_usage_type'         => ['required', 'string', 'in:commercial,project'],
            'project_id'              => ['nullable', 'exists:projects,id'],
            'exit_date'               => ['required', 'date'],
            'reason'                  => ['nullable', 'string', 'max:255'],
            'notes'                   => ['nullable', 'string'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.product_id'      => ['required', 'exists:products,id'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'      => ['sometimes', 'nullable', 'array'],
            'items.*.serial_ids.*'    => ['integer', 'exists:product_serials,id'],
        ]);

        if ($data['item_usage_type'] === 'project' && empty($data['project_id'])) {
            return back()->withErrors(['project_id' => 'Vui lòng chọn dự án khi xuất hàng cho dự án.'])->withInput();
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

        $exit = StockExit::create([
            'code'            => $data['code'],
            'warehouse_id'    => $data['warehouse_id'],
            'customer_id'     => $data['customer_id'] ?? null,
            'order_id'        => $data['order_id'] ?? null,
            'item_usage_type' => $data['item_usage_type'],
            'project_id'      => $data['item_usage_type'] === 'project' ? ($data['project_id'] ?? null) : null,
            'created_by'      => auth()->id(),
            'exit_date'       => $data['exit_date'],
            'reason'          => $data['reason'] ?? null,
            'notes'           => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $itemData) {
            $exitItem = $exit->items()->create([
                'product_id' => $itemData['product_id'],
                'quantity'   => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
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
        $stockExit->load(['warehouse', 'customer', 'order', 'creator', 'project', 'items.product', 'items.serials']);

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
                'order'        => $stockExit->order ? [
                    'id'           => $stockExit->order->id,
                    'code'         => $stockExit->order->code,
                    'status_label' => $stockExit->order->status->label(),
                    'status_color' => $stockExit->order->status->color(),
                ] : null,
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
                    'total'        => $item->quantity * $item->unit_price,
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

        $stockExit->load(['items.serials']);

        return Inertia::render('Warehouse/StockExits/Form', [
            'exit'       => [
                'id'              => $stockExit->id,
                'code'            => $stockExit->code,
                'exit_date'       => $stockExit->exit_date->format('Y-m-d'),
                'warehouse_id'    => $stockExit->warehouse_id,
                'customer_id'     => $stockExit->customer_id,
                'order_id'        => $stockExit->order_id,
                'item_usage_type' => $stockExit->item_usage_type?->value ?? 'commercial',
                'project_id'      => $stockExit->project_id,
                'reason'          => $stockExit->reason,
                'notes'           => $stockExit->notes,
                'items'           => $stockExit->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'serial_ids' => $item->serials->pluck('id')->toArray(),
                ])->values(),
            ],
            'warehouses'  => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers'   => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'products'    => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'sell_price', 'has_serial']),
            'serials'     => ProductSerial::where('status', SerialStatus::InStock)
                ->get(['id', 'product_id', 'warehouse_id', 'serial_number']),
            'orders'      => $this->pendingOrdersForDropdown(),
            'projects'    => $this->activeProjectsForDropdown(),
            'usageTypes'  => collect(ItemUsageType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
        ]);
    }

    public function update(Request $request, StockExit $stockExit): RedirectResponse
    {
        if ($stockExit->status !== StockExitStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'code'                 => ['required', 'string', Rule::unique('stock_exits', 'code')->ignore($stockExit->id)],
            'warehouse_id'         => ['required', 'exists:warehouses,id'],
            'customer_id'          => ['nullable', 'exists:customers,id'],
            'order_id'             => ['nullable', 'exists:orders,id'],
            'item_usage_type'      => ['required', 'string', 'in:commercial,project'],
            'project_id'           => ['nullable', 'exists:projects,id'],
            'exit_date'            => ['required', 'date'],
            'reason'               => ['nullable', 'string', 'max:255'],
            'notes'                => ['nullable', 'string'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'exists:products,id'],
            'items.*.quantity'     => ['required', 'integer', 'min:1'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
            'items.*.serial_ids'   => ['sometimes', 'nullable', 'array'],
            'items.*.serial_ids.*' => ['integer', 'exists:product_serials,id'],
        ]);

        if ($data['item_usage_type'] === 'project' && empty($data['project_id'])) {
            return back()->withErrors(['project_id' => 'Vui lòng chọn dự án khi xuất hàng cho dự án.'])->withInput();
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

        DB::transaction(function () use ($data, $stockExit) {
            $oldItemIds = $stockExit->items->pluck('id');
            ProductSerial::whereIn('stock_exit_item_id', $oldItemIds)
                ->update(['stock_exit_item_id' => null]);

            $stockExit->items()->delete();

            $stockExit->update([
                'code'            => $data['code'],
                'warehouse_id'    => $data['warehouse_id'],
                'customer_id'     => $data['customer_id'] ?? null,
                'order_id'        => $data['order_id'] ?? null,
                'item_usage_type' => $data['item_usage_type'],
                'project_id'      => $data['item_usage_type'] === 'project' ? ($data['project_id'] ?? null) : null,
                'exit_date'       => $data['exit_date'],
                'reason'          => $data['reason'] ?? null,
                'notes'           => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $exitItem = $stockExit->items()->create([
                    'product_id' => $itemData['product_id'],
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
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
        if (! in_array($stockExit->status, [StockExitStatus::Draft, StockExitStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa phiếu ở trạng thái nháp hoặc đã hủy.');
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
            $stockExit->items()->delete();
            $stockExit->delete();
        });

        return redirect()->route('warehouse.stock-exits.index')
            ->with('success', 'Đã xóa phiếu xuất kho.');
    }
}
