<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\AvcoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReconcileAvcoController extends Controller
{
    /**
     * Preview AVCO rebuild cho danh sách sản phẩm tại một kho.
     * Không thay đổi dữ liệu — chỉ trả về dự kiến sau khi rebuild.
     * POST /warehouse/stock-exits/reconcile-avco-preview
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'warehouse_id'  => ['required', 'integer', 'exists:warehouses,id'],
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $warehouseId = $request->integer('warehouse_id');
        $productIds  = array_values(array_unique(array_filter($request->input('product_ids'))));

        $warehouse = Warehouse::findOrFail($warehouseId);

        // AVCO balances hiện tại
        $balances = InventoryBalance::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'qty_on_hand', 'value_on_hand'])
            ->keyBy('product_id');

        // SUM active non-project movements
        $movRows = StockMovement::active()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->whereNull('project_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Giá bình quân gia quyền từ phiếu nhập kho đã xác nhận
        $entryRows = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
            ->where('stock_entries.warehouse_id', $warehouseId)
            ->whereIn('stock_entry_items.product_id', $productIds)
            ->where('stock_entries.status', 'confirmed')
            ->select('stock_entry_items.product_id')
            ->selectRaw(
                'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost'
            )
            ->selectRaw(
                'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) AS total_entry_value'
            )
            ->groupBy('stock_entry_items.product_id')
            ->get()
            ->keyBy('product_id');

        // Tên sản phẩm
        $products = DB::table('products')
            ->whereIn('id', $productIds)
            ->select('id', 'code', 'name')
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($productIds as $productId) {
            $product  = $products->get($productId);
            $balance  = $balances->get($productId);
            $movRow   = $movRows->get($productId);
            $entryRow = $entryRows->get($productId);

            $movQty     = $movRow    ? (float) $movRow->qty : 0.0;
            $balQty     = $balance   ? (float) $balance->qty_on_hand : 0.0;
            $balValue   = $balance   ? (float) $balance->value_on_hand : 0.0;
            $avgCost    = $entryRow  ? round((float) $entryRow->avg_cost, 2) : 0.0;
            $entryValue = $entryRow  ? round((float) $entryRow->total_entry_value, 2) : 0.0;

            $warnings = [];
            $canApply = true;

            if ($movQty < 0) {
                $warnings[] = 'Tổng tồn kho từ movements âm — không thể tự xử lý';
                $canApply   = false;
            }
            if ($avgCost <= 0) {
                $warnings[] = 'Không tìm thấy phiếu nhập kho có giá vốn — không xác định được giá AVCO';
                $canApply   = false;
            }
            if ($movQty <= 0 && $canApply) {
                $warnings[] = 'Không có tồn kho thực tế tại kho này';
                $canApply   = false;
            }

            $suggestedQty = max(0.0, $movQty);

            $items[] = [
                'product_id'           => $productId,
                'product_code'         => $product?->code ?? 'P#' . $productId,
                'product_name'         => $product?->name ?? '(không rõ)',
                'movement_qty'         => $movQty,
                'balance_qty_before'   => $balQty,
                'movement_value'       => $entryValue,
                'balance_value_before' => $balValue,
                'suggested_qty'        => $suggestedQty,
                'suggested_avg_cost'   => $avgCost,
                'suggested_value'      => round($suggestedQty * $avgCost, 2),
                'can_apply'            => $canApply,
                'warnings'             => $warnings,
            ];
        }

        $allCanApply = count($items) > 0 && collect($items)->every(fn ($i) => $i['can_apply']);

        return response()->json([
            'success'   => true,
            'warehouse' => $warehouse->name,
            'items'     => $items,
            'can_apply' => $allCanApply,
        ]);
    }

    /**
     * Áp dụng AVCO rebuild cho danh sách sản phẩm tại một kho.
     * Chỉ cập nhật inventory_balances — không sửa chứng từ gốc.
     * POST /warehouse/stock-exits/reconcile-avco-apply
     */
    public function apply(Request $request, AvcoService $avco): JsonResponse
    {
        $request->validate([
            'warehouse_id'  => ['required', 'integer', 'exists:warehouses,id'],
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $warehouseId = $request->integer('warehouse_id');
        $productIds  = array_values(array_unique(array_filter($request->input('product_ids'))));

        $results = [];
        $rebuilt = 0;
        $failed  = 0;

        DB::transaction(function () use ($avco, $warehouseId, $productIds, &$results, &$rebuilt, &$failed) {
            foreach ($productIds as $productId) {
                try {
                    $balance = $avco->rebuildFromEntries((int) $productId, $warehouseId);
                    if ($balance) {
                        $results[$productId] = [
                            'success'     => true,
                            'avg_cost'    => (float) $balance->avg_cost,
                            'qty_on_hand' => (float) $balance->qty_on_hand,
                        ];
                        $rebuilt++;
                    } else {
                        $results[$productId] = [
                            'success' => false,
                            'error'   => 'Không tìm thấy phiếu nhập kho có giá vốn',
                        ];
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $results[$productId] = [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ];
                    $failed++;
                }
            }
        });

        activity()
            ->causedBy(auth()->user())
            ->withProperties([
                'warehouse_id' => $warehouseId,
                'product_ids'  => $productIds,
                'rebuilt'      => $rebuilt,
                'failed'       => $failed,
            ])
            ->log('inventory.avco.reconcile_apply');

        return response()->json([
            'success' => $rebuilt > 0,
            'rebuilt' => $rebuilt,
            'failed'  => $failed,
            'results' => $results,
        ], $rebuilt > 0 ? 200 : 422);
    }
}
