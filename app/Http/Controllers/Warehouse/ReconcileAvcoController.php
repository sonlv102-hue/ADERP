<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryOpeningBalance;
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
     *
     * Nguồn giá vốn theo thứ tự ưu tiên:
     *   1. Phiếu nhập kho đã xác nhận (stock_entries confirmed)
     *   2. Tồn đầu kỳ (inventory_opening_balances)
     *   3. Giá vốn sản phẩm (products.cost_price — needs_confirm)
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

        // Nguồn 1: giá bình quân từ phiếu nhập kho đã xác nhận
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

        // Nguồn 2: tồn đầu kỳ (lấy bản ghi mới nhất có qty > 0 và unit_cost > 0)
        $openingRows = InventoryOpeningBalance::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->where('quantity', '>', 0)
            ->where('unit_cost', '>', 0)
            ->orderByDesc('id')
            ->get(['product_id', 'unit_cost', 'quantity'])
            ->keyBy('product_id');

        // Nguồn 3: giá vốn sản phẩm (cost_price — INCL VAT, cần xác nhận)
        $productRows = DB::table('products')
            ->whereIn('id', $productIds)
            ->select('id', 'code', 'name', 'cost_price')
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($productIds as $productId) {
            $product    = $productRows->get($productId);
            $balance    = $balances->get($productId);
            $movRow     = $movRows->get($productId);
            $entryRow   = $entryRows->get($productId);
            $openingRow = $openingRows->get($productId);

            $movQty   = $movRow  ? (float) $movRow->qty : 0.0;
            $balQty   = $balance ? (float) $balance->qty_on_hand : 0.0;
            $balValue = $balance ? (float) $balance->value_on_hand : 0.0;

            $warnings     = [];
            $canApply     = true;
            $needsConfirm = false;
            $sourceType   = null;
            $sourceLabel  = 'Không có nguồn';
            $avgCost      = 0.0;
            $entryValue   = 0.0;

            if ($movQty < 0) {
                $warnings[] = 'Tổng tồn kho từ movements âm — không thể tự xử lý';
                $canApply   = false;
            } elseif ($movQty <= 0) {
                $warnings[] = 'Không có tồn kho thực tế tại kho này';
                $canApply   = false;
            }

            if ($canApply) {
                if ($entryRow && (float) $entryRow->avg_cost > 0) {
                    // Ưu tiên 1: phiếu nhập kho đã xác nhận
                    $avgCost     = round((float) $entryRow->avg_cost, 2);
                    $entryValue  = round((float) $entryRow->total_entry_value, 2);
                    $sourceType  = 'stock_entry';
                    $sourceLabel = 'Phiếu nhập kho';
                } elseif ($openingRow && (float) $openingRow->unit_cost > 0) {
                    // Ưu tiên 2: tồn đầu kỳ
                    $avgCost     = round((float) $openingRow->unit_cost, 2);
                    $entryValue  = round($avgCost * $movQty, 2);
                    $sourceType  = 'opening_balance';
                    $sourceLabel = 'Tồn đầu kỳ';
                } elseif ($product && (float) ($product->cost_price ?? 0) > 0) {
                    // Ưu tiên 3: giá vốn sản phẩm (INCL VAT — cần xác nhận)
                    $avgCost      = round((float) $product->cost_price, 2);
                    $entryValue   = round($avgCost * $movQty, 2);
                    $sourceType   = 'product_cost';
                    $sourceLabel  = 'Giá vốn sản phẩm';
                    $needsConfirm = true;
                    $warnings[]   = 'Giá vốn từ danh mục sản phẩm (cost_price, đã gồm VAT) — không phải từ chứng từ nhập kho thực tế. Xác nhận trước khi áp dụng.';
                } else {
                    $warnings[] = 'Không tìm thấy nguồn giá vốn (phiếu nhập kho, tồn đầu kỳ, hoặc giá vốn sản phẩm)';
                    $canApply   = false;
                }
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
                'needs_confirm'        => $needsConfirm,
                'source_type'          => $sourceType,
                'source_label'         => $sourceLabel,
                'warnings'             => $warnings,
            ];
        }

        $allCanApply   = count($items) > 0 && collect($items)->every(fn ($i) => $i['can_apply']);
        $anyNeedConfirm = collect($items)->contains(fn ($i) => $i['needs_confirm']);

        return response()->json([
            'success'       => true,
            'warehouse'     => $warehouse->name,
            'items'         => $items,
            'can_apply'     => $allCanApply,
            'needs_confirm' => $anyNeedConfirm,
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
            // Re-resolve cost source (cùng thứ tự ưu tiên như preview)
            $entryRows = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
                ->where('stock_entries.warehouse_id', $warehouseId)
                ->whereIn('stock_entry_items.product_id', $productIds)
                ->where('stock_entries.status', 'confirmed')
                ->select('stock_entry_items.product_id')
                ->selectRaw('SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost')
                ->groupBy('stock_entry_items.product_id')
                ->get()
                ->keyBy('product_id');

            $openingRows = InventoryOpeningBalance::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productIds)
                ->where('quantity', '>', 0)
                ->where('unit_cost', '>', 0)
                ->orderByDesc('id')
                ->get(['product_id', 'unit_cost'])
                ->keyBy('product_id');

            $productCosts = DB::table('products')
                ->whereIn('id', $productIds)
                ->select('id', 'cost_price')
                ->get()
                ->keyBy('id');

            foreach ($productIds as $productId) {
                try {
                    $entryRow   = $entryRows->get($productId);
                    $openingRow = $openingRows->get($productId);
                    $productRow = $productCosts->get($productId);

                    if ($entryRow && (float) $entryRow->avg_cost > 0) {
                        $balance = $avco->rebuildFromEntries((int) $productId, $warehouseId);
                        if ($balance) {
                            $results[$productId] = ['success' => true, 'avg_cost' => (float) $balance->avg_cost, 'qty_on_hand' => (float) $balance->qty_on_hand, 'source' => 'stock_entry'];
                            $rebuilt++;
                        } else {
                            $results[$productId] = ['success' => false, 'error' => 'rebuildFromEntries trả về null'];
                            $failed++;
                        }
                    } elseif ($openingRow && (float) $openingRow->unit_cost > 0) {
                        $balance = $this->applyFromCost((int) $productId, $warehouseId, (float) $openingRow->unit_cost, 'opening_balance');
                        $results[$productId] = ['success' => true, 'avg_cost' => (float) $balance->avg_cost, 'qty_on_hand' => (float) $balance->qty_on_hand, 'source' => 'opening_balance'];
                        $rebuilt++;
                    } elseif ($productRow && (float) ($productRow->cost_price ?? 0) > 0) {
                        $balance = $this->applyFromCost((int) $productId, $warehouseId, (float) $productRow->cost_price, 'product_cost');
                        $results[$productId] = ['success' => true, 'avg_cost' => (float) $balance->avg_cost, 'qty_on_hand' => (float) $balance->qty_on_hand, 'source' => 'product_cost'];
                        $rebuilt++;
                    } else {
                        $results[$productId] = ['success' => false, 'error' => 'Không tìm thấy nguồn giá vốn'];
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $results[$productId] = ['success' => false, 'error' => $e->getMessage()];
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

    /**
     * Upsert inventory_balances với giá vốn từ nguồn không phải stock_entry.
     * qty_on_hand = SUM(active non-project movements).
     */
    private function applyFromCost(int $productId, int $warehouseId, float $unitCost, string $initializedFrom): InventoryBalance
    {
        $movQty    = (float) StockMovement::active()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('project_id')
            ->sum('quantity');

        $qtyOnHand = max(0.0, $movQty);
        $lastMov   = StockMovement::active()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->latest('id')
            ->value('id');

        return InventoryBalance::updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            [
                'qty_on_hand'      => $qtyOnHand,
                'value_on_hand'    => round($qtyOnHand * $unitCost, 2),
                'avg_cost'         => round($unitCost, 2),
                'last_movement_id' => $lastMov,
                'initialized_from' => $initializedFrom,
                'initialized_at'   => now(),
            ]
        );
    }
}
