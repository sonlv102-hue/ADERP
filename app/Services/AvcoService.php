<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use RuntimeException;

class AvcoService
{
    public function getBalance(int $productId, int $warehouseId): ?InventoryBalance
    {
        return InventoryBalance::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    /**
     * Record a non-project stock entry and update AVCO balance.
     * Must be called inside an existing DB transaction (uses lockForUpdate).
     *
     * AVCO formula: new_avg = (old_value + qty * unitCost) / (old_qty + qty)
     */
    public function recordEntry(
        int $productId,
        int $warehouseId,
        float $qty,
        float $unitCostExclVat,
        ?int $movementId = null,
    ): void {
        $inValue = $qty * $unitCostExclVat;
        $balance = InventoryBalance::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            $newQty   = (float) $balance->qty_on_hand + $qty;
            $newValue = (float) $balance->value_on_hand + $inValue;
            $newAvg   = $newQty > 0 ? $newValue / $newQty : $unitCostExclVat;

            $balance->update([
                'qty_on_hand'      => $newQty,
                'value_on_hand'    => $newValue,
                'avg_cost'         => $newAvg,
                'last_movement_id' => $movementId,
            ]);
        } else {
            InventoryBalance::create([
                'product_id'       => $productId,
                'warehouse_id'     => $warehouseId,
                'qty_on_hand'      => $qty,
                'value_on_hand'    => $inValue,
                'avg_cost'         => $unitCostExclVat,
                'last_movement_id' => $movementId,
                'initialized_from' => 'entry',
                'initialized_at'   => now(),
            ]);
        }
    }

    /**
     * Record a non-project stock exit and update AVCO balance.
     * Throws if no balance exists or avg_cost is 0.
     * Must be called inside an existing DB transaction (uses lockForUpdate).
     *
     * @return float Current avg_cost snapshot for COGS calculation
     */
    public function recordExit(int $productId, int $warehouseId, float $qty): float
    {
        $balance = InventoryBalance::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($balance === null) {
            // Thử auto-seed từ lịch sử nhập kho (an toàn khi chưa có exits nào)
            $balance = $this->seedBalanceFromEntries($productId, $warehouseId);
            if ($balance === null) {
                throw new RuntimeException(
                    "Chưa có số dư tồn kho AVCO cho sản phẩm ID {$productId} / kho ID {$warehouseId}. " .
                    "Vui lòng vào Kho → Tồn kho đầu kỳ để nhập số dư ban đầu."
                );
            }
        }

        if ((float) $balance->avg_cost <= 0 && ! Product::where('id', $productId)->value('allow_zero_cost')) {
            throw new RuntimeException(
                "Đơn giá bình quân = 0 cho sản phẩm ID {$productId}. Kiểm tra lại dữ liệu nhập kho, " .
                "hoặc nếu đây là hàng tặng/không tính giá vốn, bật \"Cho phép giá vốn = 0\" ở Sản phẩm."
            );
        }

        $avgCost   = (float) $balance->avg_cost;
        $exitValue = $avgCost * $qty;

        $balance->update([
            'qty_on_hand'   => max(0, (float) $balance->qty_on_hand - $qty),
            'value_on_hand' => max(0, (float) $balance->value_on_hand - $exitValue),
            // avg_cost giữ nguyên khi xuất; chỉ thay đổi khi có nhập mới
        ]);

        return $avgCost;
    }

    /**
     * Rebuild (upsert) inventory_balance from confirmed stock_entry_items.
     * Used by reconcile command to fix AVCO rows that are missing or have wrong qty.
     * avg_cost = weighted average of unit_price across all confirmed entries.
     * qty_on_hand = SUM(active movements, non-project).
     * Returns null if no entry data with positive cost found.
     */
    public function rebuildFromEntries(int $productId, int $warehouseId): ?InventoryBalance
    {
        $entryData = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
            ->where('stock_entries.warehouse_id', $warehouseId)
            ->where('stock_entry_items.product_id', $productId)
            ->where('stock_entries.status', 'confirmed')
            ->selectRaw(
                'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost'
            )
            ->first();

        if (! $entryData || ! $entryData->avg_cost || (float) $entryData->avg_cost <= 0) {
            return null;
        }

        $currentQty = (float) StockMovement::active()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('project_id')
            ->sum('quantity');

        $avgCost   = round((float) $entryData->avg_cost, 2);
        $qtyOnHand = max(0.0, $currentQty);
        $lastMov   = StockMovement::active()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->latest('id')
            ->value('id');

        return InventoryBalance::updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            [
                'qty_on_hand'      => $qtyOnHand,
                'value_on_hand'    => round($qtyOnHand * $avgCost, 2),
                'avg_cost'         => $avgCost,
                'last_movement_id' => $lastMov,
                'initialized_from' => 'reconcile_rebuild',
                'initialized_at'   => now(),
            ]
        );
    }

    /**
     * Auto-seed inventory_balances from confirmed stock_entry_items.
     * Called when recordExit finds no existing balance (e.g., AVCO module was added after entries).
     * Safe only when no prior exits exist for this product+warehouse (caller must ensure this).
     */
    private function seedBalanceFromEntries(int $productId, int $warehouseId): ?InventoryBalance
    {
        $entryData = StockEntryItem::join('stock_entries', 'stock_entries.id', '=', 'stock_entry_items.stock_entry_id')
            ->where('stock_entries.warehouse_id', $warehouseId)
            ->where('stock_entry_items.product_id', $productId)
            ->where('stock_entries.status', 'confirmed')
            ->selectRaw(
                'SUM(stock_entry_items.quantity * stock_entry_items.unit_price) / NULLIF(SUM(stock_entry_items.quantity), 0) AS avg_cost'
            )
            ->first();

        if (! $entryData || ! $entryData->avg_cost || (float) $entryData->avg_cost <= 0) {
            return null;
        }

        $currentQty = (float) StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('project_id')
            ->sum('quantity');

        $avgCost   = round((float) $entryData->avg_cost, 2);
        $qtyOnHand = max(0.0, $currentQty);

        return InventoryBalance::create([
            'product_id'       => $productId,
            'warehouse_id'     => $warehouseId,
            'qty_on_hand'      => $qtyOnHand,
            'value_on_hand'    => round($qtyOnHand * $avgCost, 2),
            'avg_cost'         => $avgCost,
            'initialized_from' => 'auto_from_entries',
            'initialized_at'   => now(),
        ]);
    }

    /**
     * Initialize or reset AVCO balance from an opening balance entry.
     * Used by InventoryOpeningBalanceController when submitting opening stock.
     */
    public function initializeFromOpeningBalance(
        int $productId,
        int $warehouseId,
        float $qty,
        float $unitCost,
    ): void {
        InventoryBalance::updateOrCreate(
            [
                'product_id'   => $productId,
                'warehouse_id' => $warehouseId,
            ],
            [
                'qty_on_hand'      => $qty,
                'value_on_hand'    => round($qty * $unitCost, 2),
                'avg_cost'         => $unitCost,
                'initialized_from' => 'opening_balance',
                'initialized_at'   => now(),
            ]
        );
    }
}
