<?php

namespace App\Services;

use App\Models\InventoryBalance;
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
            throw new RuntimeException(
                "Chưa có số dư tồn kho AVCO cho sản phẩm ID {$productId} / kho ID {$warehouseId}. " .
                "Vui lòng vào Kho → Rà soát giá vốn → tab Khởi tạo AVCO để nhập tồn đầu kỳ."
            );
        }

        if ((float) $balance->avg_cost <= 0) {
            throw new RuntimeException(
                "Đơn giá bình quân = 0 cho sản phẩm ID {$productId}. Kiểm tra lại dữ liệu nhập kho."
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
