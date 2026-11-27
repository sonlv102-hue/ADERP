<?php

namespace App\Services\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Integrity Check (READ-ONLY).
 *
 * Bất biến: AVCO kho chung (inventory_balances.qty_on_hand)
 *           = Σ stock_movements ACTIVE − Σ tồn còn lại của project lots chưa hủy
 * (hàng dự án nằm trong movements nhưng được theo dõi bằng lot FIFO, không nằm trong AVCO).
 *
 * Không dùng logic của ReconcileInventoryBalancesCommand (whereNull('m.project_id') loại nhầm
 * movement xuất dự án lấy từ kho chung — false positive đã biết).
 */
class AvcoIntegrityChecker
{
    public const PHANTOM          = 'phantom_stock';      // AVCO > thực tế → có thể xuất hàng không có thật
    public const MISSING          = 'missing_stock';      // AVCO < thực tế → có hàng nhưng không xuất được
    public const NEGATIVE         = 'negative_movement';  // Σ movement âm → lịch sử xuất trước nhập
    public const NO_BALANCE       = 'no_avco_row';        // chưa có dòng AVCO, hệ thống dùng fallback movements
    public const VALUE_MISMATCH   = 'value_mismatch';     // qty khớp nhưng value ≠ qty × avg (hoặc qty=0, value≠0)

    /** @return Collection<int, object> */
    public function check(?int $warehouseId = null, ?array $productIds = null): Collection
    {
        $rows = DB::select($this->sql($warehouseId, $productIds), $this->bindings($warehouseId, $productIds));

        return collect($rows)->map(function ($r) {
            $r->avco_qty    = $r->avco_qty === null ? null : (float) $r->avco_qty;
            $r->avco_value  = $r->avco_value === null ? null : (float) $r->avco_value;
            $r->avg_cost    = $r->avg_cost === null ? null : (float) $r->avg_cost;
            $r->mv_qty      = (float) $r->mv_qty;
            $r->lot_qty     = (float) $r->lot_qty;
            $r->expected    = $r->mv_qty - $r->lot_qty;
            $r->diff        = $r->avco_qty === null ? null : round($r->avco_qty - $r->expected, 3);
            $r->category    = $this->classify($r);
            return $r;
        })->filter(fn ($r) => $r->category !== null)->values();
    }

    private function classify(object $r): ?string
    {
        if ($r->expected < 0) {
            return self::NEGATIVE;
        }
        if ($r->avco_qty === null) {
            return $r->expected != 0 ? self::NO_BALANCE : null;
        }
        if ($r->diff > 0.0005) {
            return self::PHANTOM;
        }
        if ($r->diff < -0.0005) {
            return self::MISSING;
        }
        $expectedValue = $r->avco_qty * $r->avg_cost;
        if (($r->avco_qty == 0 && abs($r->avco_value) > 1) || ($r->avco_qty > 0 && abs($r->avco_value - $expectedValue) > max(1, $r->avco_qty))) {
            return self::VALUE_MISMATCH;
        }
        return null;
    }

    private function sql(?int $warehouseId, ?array $productIds): string
    {
        $filter = '';
        if ($warehouseId) {
            $filter .= ' AND k.w = ?';
        }
        if ($productIds) {
            $filter .= ' AND k.p IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')';
        }

        return "
            WITH mv AS (
                SELECT product_id p, warehouse_id w, SUM(quantity) q FROM stock_movements
                WHERE status = 'active' GROUP BY product_id, warehouse_id
            ), lot AS (
                SELECT product_id p, warehouse_id w, SUM(received_qty - issued_qty) q FROM project_inventory_lots
                WHERE status <> 'cancelled' GROUP BY product_id, warehouse_id
            ), k AS (
                SELECT p, w FROM mv UNION SELECT product_id, warehouse_id FROM inventory_balances UNION SELECT p, w FROM lot
            )
            SELECT k.p AS product_id, k.w AS warehouse_id, pr.code AS product_code,
                   b.qty_on_hand AS avco_qty, b.value_on_hand AS avco_value, b.avg_cost,
                   COALESCE(mv.q, 0) AS mv_qty, COALESCE(lot.q, 0) AS lot_qty
            FROM k
            JOIN products pr ON pr.id = k.p
            LEFT JOIN inventory_balances b ON b.product_id = k.p AND b.warehouse_id = k.w
            LEFT JOIN mv ON mv.p = k.p AND mv.w = k.w
            LEFT JOIN lot ON lot.p = k.p AND lot.w = k.w
            WHERE 1 = 1 {$filter}
            ORDER BY k.w, k.p";
    }

    private function bindings(?int $warehouseId, ?array $productIds): array
    {
        $bindings = $warehouseId ? [$warehouseId] : [];

        return array_merge($bindings, array_values($productIds ?? []));
    }
}
