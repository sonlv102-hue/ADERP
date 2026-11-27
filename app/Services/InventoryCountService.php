<?php

namespace App\Services;

use App\Enums\InventoryCountStatus;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\AccountingSettings;
use App\Services\Concerns\LocksDocumentStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Services\AccountingService;

class InventoryCountService
{
    use LocksDocumentStatus;

    public function __construct(private AvcoService $avco) {}

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

        $count->load(['items', 'items.product']);

        $unentered = $count->items->whereNull('counted_quantity');
        if ($unentered->count() > 0) {
            throw new RuntimeException(
                "Còn {$unentered->count()} sản phẩm chưa nhập số lượng thực đếm. Vui lòng nhập đủ trước khi xác nhận."
            );
        }

        DB::transaction(function () use ($count) {
            $this->lockAndAssertStatus($count, [InventoryCountStatus::Draft], 'Phiếu kiểm kê đã được xử lý bởi thao tác khác.');
            $journalLines = [];

            foreach ($count->items as $item) {
                $difference = $item->counted_quantity - $item->system_quantity;
                if ($difference == 0) {
                    continue;
                }

                // A4: giá trị chênh lệch theo AVCO (giá vốn chưa VAT), không theo cost_price (đã gồm VAT).
                // Thiếu: trừ AVCO theo avg. Thừa: cộng theo avg hiện tại (PENDING_DECISION: chính sách giá trị hàng thừa).
                $qty = abs((float) $difference);
                if ($difference < 0) {
                    $unitCost = $this->avco->recordExit($item->product_id, (int) $count->warehouse_id, $qty);
                } else {
                    $unitCost = (float) (InventoryBalance::where('product_id', $item->product_id)
                        ->where('warehouse_id', $count->warehouse_id)->value('avg_cost') ?? 0);
                    if ($unitCost <= 0) {
                        throw new RuntimeException("Sản phẩm [{$item->product->code}] chưa có giá bình quân tại kho — chưa xác định được giá trị hàng thừa. Cần kế toán quyết định giá trị trước khi xác nhận.");
                    }
                    $this->avco->recordEntry($item->product_id, (int) $count->warehouse_id, $qty, $unitCost);
                }

                StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $count->warehouse_id,
                    'type'         => 'adjustment',
                    'quantity'     => (int) $difference,
                    'source_type'  => InventoryCount::class,
                    'source_id'    => $count->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Điều chỉnh kiểm kê {$count->code}",
                    'source_item_id' => $item->id,
                    'unit_cost'    => $unitCost,
                    'amount'       => round(($difference < 0 ? -1 : 1) * $qty * $unitCost, 2),
                ]);

                $costAmount = (int) round($qty * $unitCost);
                if ($costAmount <= 0) {
                    continue;
                }

                $productLabel = $item->product->name;
                $inventoryAccount = $item->product->inventory_account
                    ?? AccountingSettings::get('default_inventory_account', '1561');

                if ($difference > 0) {
                    // Thừa kho: Nợ 156x / Có 711
                    $journalLines[] = ['account' => $inventoryAccount, 'debit' => $costAmount, 'credit' => 0, 'description' => "Thừa kho: {$productLabel}"];
                    $journalLines[] = ['account' => AccountingSettings::get('inventory_surplus_account', '711'), 'debit' => 0, 'credit' => $costAmount, 'description' => "Thừa kho: {$productLabel}"];
                } else {
                    // Thiếu kho: Nợ 811 / Có 156x
                    $journalLines[] = ['account' => AccountingSettings::get('inventory_shortage_account', '811'), 'debit' => $costAmount, 'credit' => 0, 'description' => "Thiếu kho: {$productLabel}"];
                    $journalLines[] = ['account' => $inventoryAccount, 'debit' => 0, 'credit' => $costAmount, 'description' => "Thiếu kho: {$productLabel}"];
                }
            }

            if (!empty($journalLines)) {
                app(AccountingService::class)->post(
                    "Điều chỉnh kiểm kê kho {$count->code}",
                    $count->count_date,
                    $journalLines,
                    InventoryCount::class,
                    $count->id,
                    true
                );
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
