<?php

namespace App\Http\Controllers\Warehouse;

use App\Enums\StockTransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $svc) {}

    public function index(): Response
    {
        return Inertia::render('Warehouse/StockTransfers/Index', [
            'transfers' => StockTransfer::with(['fromWarehouse', 'toWarehouse', 'creator'])
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($t) => [
                    'id'             => $t->id,
                    'code'           => $t->code,
                    'transfer_date'  => $t->transfer_date->format('d/m/Y'),
                    'from_warehouse' => $t->fromWarehouse->name,
                    'to_warehouse'   => $t->toWarehouse->name,
                    'status'         => $t->status->value,
                    'status_label'   => $t->status->label(),
                    'status_color'   => $t->status->color(),
                    'creator'        => $t->creator->name,
                    'items_count'    => $t->items_count,
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $fromWarehouseId = $request->query('from_warehouse_id') ? (int) $request->query('from_warehouse_id') : null;

        return Inertia::render('Warehouse/StockTransfers/Form', [
            'nextCode'   => StockTransfer::generateCode(),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'products'   => $this->getProductsWithStock($fromWarehouseId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                => ['required', 'string', 'unique:stock_transfers,code'],
            'from_warehouse_id'   => ['required', 'exists:warehouses,id'],
            'to_warehouse_id'     => ['required', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'transfer_date'       => ['required', 'date'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.serial_ids'  => ['nullable', 'array'],
            'items.*.serial_ids.*' => ['integer', 'exists:product_serials,id'],
        ]);

        // Validate serial ownership before saving
        foreach ($data['items'] as $idx => $item) {
            if (!empty($item['serial_ids'])) {
                $validCount = ProductSerial::whereIn('id', $item['serial_ids'])
                    ->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $data['from_warehouse_id'])
                    ->where('status', 'in_stock')
                    ->count();
                if ($validCount !== count($item['serial_ids'])) {
                    return back()->withErrors(["items.{$idx}.serial_ids" => 'Một số serial không hợp lệ (sai sản phẩm, sai kho, hoặc không trong kho).'])->withInput();
                }
            }
        }

        $transfer = DB::transaction(function () use ($data) {
            $transfer = StockTransfer::create([
                'code'              => $data['code'],
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id'   => $data['to_warehouse_id'],
                'transfer_date'     => $data['transfer_date'],
                'notes'             => $data['notes'] ?? null,
                'created_by'        => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                $transferItem = $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                ]);

                // Assign selected serials to this transfer item
                if (!empty($item['serial_ids'])) {
                    ProductSerial::whereIn('id', $item['serial_ids'])
                        ->update(['stock_transfer_item_id' => $transferItem->id]);
                }
            }

            return $transfer;
        });

        return redirect()->route('warehouse.stock-transfers.show', $transfer)
            ->with('success', 'Đã tạo phiếu chuyển kho.');
    }

    public function show(StockTransfer $stockTransfer): Response
    {
        $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'creator', 'items.product', 'items.serials']);

        return Inertia::render('Warehouse/StockTransfers/Show', [
            'transfer' => [
                'id'             => $stockTransfer->id,
                'code'           => $stockTransfer->code,
                'transfer_date'  => $stockTransfer->transfer_date->format('d/m/Y'),
                'status'         => $stockTransfer->status->value,
                'status_label'   => $stockTransfer->status->label(),
                'status_color'   => $stockTransfer->status->color(),
                'from_warehouse' => $stockTransfer->fromWarehouse->name,
                'to_warehouse'   => $stockTransfer->toWarehouse->name,
                'creator'        => $stockTransfer->creator->name,
                'notes'          => $stockTransfer->notes,
                'items'          => $stockTransfer->items->map(fn ($item) => [
                    'id'           => $item->id,
                    'product_code' => $item->product->code,
                    'product_name' => $item->product->name,
                    'unit'         => $item->product->unit,
                    'has_serial'   => $item->product->has_serial,
                    'quantity'     => $item->quantity,
                    'serials'      => $item->serials->map(fn ($s) => [
                        'serial_number' => $s->serial_number,
                    ]),
                ]),
            ],
        ]);
    }

    public function edit(Request $request, StockTransfer $stockTransfer): Response|RedirectResponse
    {
        if ($stockTransfer->status !== StockTransferStatus::Draft) {
            return redirect()->route('warehouse.stock-transfers.show', $stockTransfer)
                ->with('error', 'Chỉ có thể chỉnh sửa phiếu ở trạng thái nháp.');
        }

        $stockTransfer->load(['items.serials']);

        // Allow overriding warehouse for serial reload via query param
        $fromWarehouseId = $request->query('from_warehouse_id')
            ? (int) $request->query('from_warehouse_id')
            : $stockTransfer->from_warehouse_id;

        return Inertia::render('Warehouse/StockTransfers/Form', [
            'transfer'   => [
                'id'                => $stockTransfer->id,
                'code'              => $stockTransfer->code,
                'from_warehouse_id' => $stockTransfer->from_warehouse_id,
                'to_warehouse_id'   => $stockTransfer->to_warehouse_id,
                'transfer_date'     => $stockTransfer->transfer_date->format('Y-m-d'),
                'notes'             => $stockTransfer->notes,
                'items'             => $stockTransfer->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'serial_ids' => $item->serials->pluck('id')->toArray(),
                ]),
            ],
            'nextCode'   => null,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'products'   => $this->getProductsWithStock($fromWarehouseId),
        ]);
    }

    public function update(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        if ($stockTransfer->status !== StockTransferStatus::Draft) {
            return back()->with('error', 'Chỉ có thể chỉnh sửa phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'from_warehouse_id'   => ['required', 'exists:warehouses,id'],
            'to_warehouse_id'     => ['required', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'transfer_date'       => ['required', 'date'],
            'notes'               => ['nullable', 'string'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.product_id'  => ['required', 'exists:products,id'],
            'items.*.quantity'    => ['required', 'integer', 'min:1'],
            'items.*.serial_ids'  => ['nullable', 'array'],
            'items.*.serial_ids.*' => ['integer', 'exists:product_serials,id'],
        ]);

        // Validate serial ownership before saving
        foreach ($data['items'] as $idx => $item) {
            if (!empty($item['serial_ids'])) {
                $validCount = ProductSerial::whereIn('id', $item['serial_ids'])
                    ->where('product_id', $item['product_id'])
                    ->where('warehouse_id', $data['from_warehouse_id'])
                    ->where('status', 'in_stock')
                    ->count();
                if ($validCount !== count($item['serial_ids'])) {
                    return back()->withErrors(["items.{$idx}.serial_ids" => 'Một số serial không hợp lệ (sai sản phẩm, sai kho, hoặc không trong kho).'])->withInput();
                }
            }
        }

        DB::transaction(function () use ($data, $stockTransfer) {
            // Detach old serials before deleting items
            $oldItemIds = $stockTransfer->items()->pluck('id');
            ProductSerial::whereIn('stock_transfer_item_id', $oldItemIds)
                ->update(['stock_transfer_item_id' => null]);

            $stockTransfer->items()->delete();

            $stockTransfer->update([
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id'   => $data['to_warehouse_id'],
                'transfer_date'     => $data['transfer_date'],
                'notes'             => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $transferItem = $stockTransfer->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                ]);

                if (!empty($item['serial_ids'])) {
                    ProductSerial::whereIn('id', $item['serial_ids'])
                        ->update(['stock_transfer_item_id' => $transferItem->id]);
                }
            }
        });

        return redirect()->route('warehouse.stock-transfers.show', $stockTransfer)
            ->with('success', 'Đã cập nhật phiếu chuyển kho.');
    }

    public function destroy(StockTransfer $stockTransfer): RedirectResponse
    {
        if ($stockTransfer->status === StockTransferStatus::Confirmed) {
            return back()->with('error', 'Không thể xóa phiếu đã xác nhận.');
        }

        DB::transaction(function () use ($stockTransfer) {
            $itemIds = $stockTransfer->items()->pluck('id');
            ProductSerial::whereIn('stock_transfer_item_id', $itemIds)
                ->update(['stock_transfer_item_id' => null]);
            $stockTransfer->delete();
        });

        return redirect()->route('warehouse.stock-transfers.index')
            ->with('success', 'Đã xóa phiếu chuyển kho.');
    }

    public function confirm(StockTransfer $stockTransfer): RedirectResponse
    {
        try {
            $this->svc->confirmTransfer($stockTransfer);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xác nhận phiếu chuyển kho.');
    }

    public function cancel(StockTransfer $stockTransfer): RedirectResponse
    {
        try {
            $this->svc->cancelTransfer($stockTransfer);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy phiếu chuyển kho.');
    }

    /**
     * Returns products with their in-stock serials per warehouse for the form.
     */
    private function getProductsWithStock(?int $warehouseId = null): array
    {
        $products = Product::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit', 'has_serial']);

        // Pre-load all serials for this warehouse to avoid N+1
        $allSerials = $warehouseId
            ? ProductSerial::where('warehouse_id', $warehouseId)
                ->where('status', 'in_stock')
                ->get(['id', 'product_id', 'serial_number'])
                ->groupBy('product_id')
            : collect();

        return $products->map(function ($product) use ($warehouseId, $allSerials) {
            $data = [
                'id'         => $product->id,
                'code'       => $product->code,
                'name'       => $product->name,
                'unit'       => $product->unit,
                'has_serial' => $product->has_serial,
            ];

            if ($warehouseId && $product->has_serial) {
                $data['serials'] = ($allSerials[$product->id] ?? collect())->map(fn ($s) => [
                    'id'            => $s->id,
                    'serial_number' => $s->serial_number,
                ])->values()->toArray();
            } else {
                $data['serials'] = [];
            }

            return $data;
        })->toArray();
    }
}
