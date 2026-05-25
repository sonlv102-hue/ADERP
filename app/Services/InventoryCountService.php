<?php

namespace App\Services;

use App\Enums\InventoryCountStatus;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryCountService
{
    /**
     * Load all products that have stock (or have had movements) in the warehouse,
     * creating InventoryCountItems with system_quantity snapshot.
     */
    public function populateItems(InventoryCount $count): void
    {
        // Get current stock per product in this warehouse
        $stockMap = StockMovement::where('warehouse_id', $count->warehouse_id)
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        // Only include products with qty > 0 (actually in stock)
        $productIds = $stockMap->filter(fn($q) => $q > 0)->keys();

        if ($productIds->isEmpty()) {
            return;
        }

        $items = $productIds->map(fn($pid) => [
            'inventory_count_id' => $count->id,
            'product_id'         => $pid,
            'system_quantity'    => (float) $stockMap[$pid],
            'counted_quantity'   => null,
            'notes'              => null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ])->values()->all();

        InventoryCountItem::insert($items);
    }

    /**
     * Save counted quantities submitted from the Show form.
     */
    public function saveItems(InventoryCount $count, array $items): void
    {
        if ($count->status !== InventoryCountStatus::Draft) {
            throw new RuntimeException('Chỉ có thể cập nhật phiếu kiểm kê ở trạng thái nháp.');
        }

        DB::transaction(function () use ($count, $items) {
            foreach ($items as $item) {
                InventoryCountItem::where('id', $item['id'])
                    ->where('inventory_count_id', $count->id)
                    ->update([
                        'counted_quantity' => $item['counted_quantity'] ?? null,
                        'notes'            => $item['notes'] ?? null,
                    ]);
            }
        });
    }

    /**
     * Confirm the inventory count: create adjustment stock movements for discrepancies.
     */
    public function confirm(InventoryCount $count): void
    {
        if ($count->status !== InventoryCountStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        $count->load('items');
        $unentered = $count->items->whereNull('counted_quantity');
        if ($unentered->count() > 0) {
            throw new RuntimeException(
                "Còn {$unentered->count()} sản phẩm chưa nhập số lượng thực đếm. Vui lòng nhập đủ trước khi xác nhận."
            );
        }

        DB::transaction(function () use ($count) {
            foreach ($count->items as $item) {
                $difference = $item->counted_quantity - $item->system_quantity;
                if ($difference == 0) {
                    continue;
                }

                StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $count->warehouse_id,
                    'type'         => 'adjustment',
                    'quantity'     => $difference,
                    'source_type'  => InventoryCount::class,
                    'source_id'    => $count->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Điều chỉnh kiểm kê {$count->code}",
                ]);
            }

            $count->update(['status' => InventoryCountStatus::Confirmed]);
        });
    }

    /**
     * Cancel a draft inventory count.
     */
    public function cancel(InventoryCount $count): void
    {
        if ($count->status !== InventoryCountStatus::Draft) {
            throw new RuntimeException('Chỉ có thể hủy phiếu ở trạng thái nháp.');
        }

        $count->update(['status' => InventoryCountStatus::Cancelled]);
    }
}
