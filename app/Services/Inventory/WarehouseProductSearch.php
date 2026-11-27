<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProjectInventoryLot;
use Illuminate\Support\Facades\DB;

/**
 * Tìm sản phẩm còn tồn trong một kho cho form xuất kho (READ-ONLY).
 * Tồn khả dụng dùng CÙNG nguồn với backend StockService::confirmExit:
 *  - Kho chung: inventory_balances.qty_on_hand; chưa có dòng AVCO → Σ movement active không dự án
 *    (getAvcoAvailableQty).
 *  - project_cost: lô FIFO active của dự án + phần kho chung ở trên (backend lấy lô trước, thiếu thì AVCO).
 * Sắp xếp: khớp chính xác SKU trước, sau đó theo mã. Phân trang ?page=, trả meta.has_more / total
 * để không mã nào bị cắt ngầm.
 */
class WarehouseProductSearch
{
    public const PER_PAGE = 200;

    public function search(int $warehouseId, ?int $projectId, string $keyword, int $page = 1): array
    {
        $general = $this->generalStock($warehouseId);
        $lots = $projectId ? $this->projectLots($warehouseId, $projectId) : collect();
        $ids = $general->keys()->merge($lots->keys())->unique()->values();

        $query = Product::whereIn('id', $ids->all())->where('is_active', true)
            ->when($keyword !== '', fn ($q) => $q->where(fn ($b) => $b
                ->whereRaw('LOWER(name) LIKE ?', ["%{$keyword}%"])
                ->orWhereRaw('LOWER(code) LIKE ?', ["%{$keyword}%"])));
        $total = (clone $query)->count();
        $page = max(1, $page);
        $products = $query
            ->orderByRaw('CASE WHEN LOWER(code) = ? THEN 0 ELSE 1 END', [$keyword])
            ->orderBy('code')->orderBy('id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $data = $products->map(function (Product $p) use ($general, $lots) {
            [$gQty, $gCost] = $general[$p->id] ?? [0.0, 0.0];
            [$lQty, $lValue] = $lots[$p->id] ?? [0.0, 0.0];
            $qty = round($gQty + $lQty, 3);
            $avg = $qty > 0 ? round(($lValue + $gQty * $gCost) / $qty, 2) : 0.0;
            $meta = "Tồn: {$qty}" . ($p->unit ? " {$p->unit}" : '');
            if ($lQty > 0 && $gQty > 0) {
                $meta .= " (lô DA: {$lQty}, kho chung: {$gQty})";
            }
            return [
                'value' => $p->id, 'label' => $p->name, 'code' => $p->code, 'meta' => $meta,
                'unit' => $p->unit, 'qty' => $qty, 'avg_cost' => $avg,
                'sell_price' => (float) ($p->sell_price ?? 0), 'has_serial' => (bool) ($p->has_serial ?? false),
            ];
        })->values();

        return ['data' => $data, 'meta' => [
            'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
            'has_more' => $page * self::PER_PAGE < $total,
        ]];
    }

    /** product_id => [qty, avg_cost] tồn kho chung > 0. */
    private function generalStock(int $warehouseId)
    {
        $avco = InventoryBalance::where('warehouse_id', $warehouseId)->get(['product_id', 'qty_on_hand', 'avg_cost'])->keyBy('product_id');
        $out = $avco->filter(fn ($b) => (float) $b->qty_on_hand > 0)
            ->map(fn ($b) => [(float) $b->qty_on_hand, (float) $b->avg_cost]);

        $fallback = DB::table('stock_movements')->where('warehouse_id', $warehouseId)->whereNull('project_id')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->whereNotIn('product_id', $avco->keys()->all() ?: [0])
            ->groupBy('product_id')->havingRaw('SUM(quantity) > 0')
            ->select('product_id', DB::raw('SUM(quantity) AS qty'))->pluck('qty', 'product_id');
        if ($fallback->isNotEmpty()) {
            $cost = DB::table('stock_entry_items as i')->join('stock_entries as e', 'e.id', '=', 'i.stock_entry_id')
                ->where('e.warehouse_id', $warehouseId)->where('e.status', 'confirmed')
                ->whereIn('i.product_id', $fallback->keys()->all())->groupBy('i.product_id')
                ->selectRaw('i.product_id, SUM(i.quantity * i.unit_price) / NULLIF(SUM(i.quantity), 0) AS c')
                ->pluck('c', 'product_id');
            foreach ($fallback as $pid => $qty) {
                $out[$pid] = [(float) $qty, (float) ($cost[$pid] ?? 0)];
            }
        }
        return $out;
    }

    /** product_id => [qty, value] lô FIFO còn lại của dự án (cùng điều kiện backend). */
    private function projectLots(int $warehouseId, int $projectId)
    {
        return ProjectInventoryLot::where('project_id', $projectId)->where('warehouse_id', $warehouseId)
            ->where('status', 'active')->whereRaw('issued_qty < received_qty')->get()
            ->groupBy('product_id')
            ->map(fn ($g) => [
                (float) $g->sum(fn ($l) => (float) $l->received_qty - (float) $l->issued_qty),
                (float) $g->sum(fn ($l) => ((float) $l->received_qty - (float) $l->issued_qty) * (float) ($l->unit_cost ?? 0)),
            ]);
    }
}
