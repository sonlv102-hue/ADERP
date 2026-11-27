<?php

namespace App\Services;

use App\Enums\PurchaseReturnStatus;
use App\Enums\SerialStatus;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockEntryItem;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\Concerns\LocksDocumentStatus;
use App\Services\Inventory\ReturnCostResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseReturnService
{
    use LocksDocumentStatus;

    public function __construct(private AvcoService $avco, private ReturnCostResolver $costs) {}

    public function confirmReturn(PurchaseReturn $return): void
    {
        if ($return->status !== PurchaseReturnStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }
        // A4: chặn trước mọi ghi dữ liệu — không thay đổi một phần tồn kho/kế toán
        app(\App\Services\Inventory\ReturnPostingGuard::class)->assertConfirmable('phiếu trả hàng NCC ' . $return->code);

        $return->load('items.product');

        DB::transaction(function () use ($return) {
            $this->lockAndAssertStatus($return, [PurchaseReturnStatus::Draft], 'Phiếu trả NCC đã được xử lý bởi thao tác khác.');
            $return->load('items.product', 'items.serials');
            $unitCosts = [];
            // Validate quantities inside transaction to prevent TOCTOU races
            foreach ($return->items as $item) {
                // Total received from confirmed stock entries linked to this PO + product
                $totalReceived = StockEntryItem::whereHas(
                    'stockEntry',
                    fn ($q) => $q->where('purchase_order_id', $return->purchase_order_id)
                                 ->where('status', 'confirmed')
                )->where('product_id', $item->product_id)->sum('quantity');

                // Prior confirmed returns for same PO item
                $priorReturned = PurchaseReturnItem::whereHas(
                    'purchaseReturn',
                    fn ($q) => $q->where('purchase_order_id', $return->purchase_order_id)
                                 ->where('status', PurchaseReturnStatus::Confirmed)
                                 ->where('id', '!=', $return->id)
                )->where('purchase_order_item_id', $item->purchase_order_item_id)->sum('quantity');

                $maxReturnable = $totalReceived - $priorReturned;

                if ($item->quantity > $maxReturnable) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}]: số lượng trả ({$item->quantity}) vượt quá có thể trả ({$maxReturnable})."
                    );
                }

                // A8: tồn khả dụng kho chung theo AVCO (khóa dòng), không SUM movement thô (gồm cả voided)
                $currentStock = (float) (InventoryBalance::where('product_id', $item->product_id)
                    ->where('warehouse_id', $return->warehouse_id)->lockForUpdate()->value('qty_on_hand') ?? 0);
                $unitCosts[$item->id] = $this->costs->purchaseReturnUnitCost($item, (int) $return->warehouse_id);

                if ($currentStock < $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}] không đủ tồn kho tại kho này. Hiện có: {$currentStock}, cần trả: {$item->quantity}."
                    );
                }
            }

            foreach ($return->items as $item) {
                $cost = $unitCosts[$item->id];
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -$item->quantity,
                    'source_type' => PurchaseReturn::class,
                    'source_id'   => $return->id,
                    'source_item_id' => $item->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Trả NCC {$return->code}",
                    'unit_cost'   => $cost,
                    'amount'      => -round($cost * (float) $item->quantity, 2),
                ]);
                // A4: giảm AVCO đúng giá nhập gốc của hàng trả
                $this->avco->reverseEntry($item->product_id, (int) $return->warehouse_id, (float) $item->quantity, $cost);

                // Update serials linked to this return item → ReturnedToSupplier
                $item->serials()->each(function ($serial) {
                    $serial->transition(SerialStatus::ReturnedToSupplier);
                });
            }

            $return->update(['status' => PurchaseReturnStatus::Confirmed]);
        });
    }

    public function cancelReturn(PurchaseReturn $return): void
    {
        if ($return->status === PurchaseReturnStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        if ($return->status === PurchaseReturnStatus::Draft) {
            $return->update(['status' => PurchaseReturnStatus::Cancelled]);
            return;
        }

        // Confirmed → reversal movements + restore serials
        $return->load('items.serials');

        DB::transaction(function () use ($return) {
            $this->lockAndAssertStatus($return, [PurchaseReturnStatus::Confirmed], 'Phiếu trả NCC đã được xử lý bởi thao tác khác.');
            $return->load('items.serials');
            foreach ($return->items as $item) {
                // Hoàn đúng giá trị đã ghi khi xác nhận (movement gốc); phiếu cũ không có giá trị → chỉ hoàn số lượng theo avg
                $origQ = StockMovement::active()->where('source_type', PurchaseReturn::class)->where('source_id', $return->id)
                    ->where('product_id', $item->product_id)->where('quantity', '<', 0);
                $orig = (clone $origQ)->where('source_item_id', $item->id)->value('unit_cost') ?? $origQ->whereNull('source_item_id')->value('unit_cost');
                $cost = $orig !== null ? (float) $orig
                    : (float) (InventoryBalance::where('product_id', $item->product_id)->where('warehouse_id', $return->warehouse_id)->value('avg_cost') ?? 0);
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'in',
                    'quantity'    => $item->quantity,
                    'source_type' => PurchaseReturn::class,
                    'source_id'   => $return->id,
                    'source_item_id' => $item->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy phiếu trả NCC {$return->code}",
                    'unit_cost'   => $cost,
                    'amount'      => round($cost * (float) $item->quantity, 2),
                ]);
                $this->avco->recordEntry($item->product_id, (int) $return->warehouse_id, (float) $item->quantity, $cost);

                // Restore serials → InStock and clear FK
                foreach ($item->serials as $serial) {
                    $serial->update(['purchase_return_item_id' => null]);
                    $serial->transition(SerialStatus::InStock);
                }
            }

            $return->update(['status' => PurchaseReturnStatus::Cancelled]);
        });
    }
}
