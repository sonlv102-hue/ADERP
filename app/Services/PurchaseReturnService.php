<?php

namespace App\Services;

use App\Enums\PurchaseReturnStatus;
use App\Enums\SerialStatus;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseReturnService
{
    public function confirmReturn(PurchaseReturn $return): void
    {
        if ($return->status !== PurchaseReturnStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        $return->load('items.product');

        DB::transaction(function () use ($return) {
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

                // Check current warehouse stock is sufficient
                $currentStock = StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $return->warehouse_id)
                    ->sum('quantity');

                if ($currentStock < $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}] không đủ tồn kho tại kho này. Hiện có: {$currentStock}, cần trả: {$item->quantity}."
                    );
                }
            }

            foreach ($return->items as $item) {
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -$item->quantity,
                    'source_type' => PurchaseReturn::class,
                    'source_id'   => $return->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Trả NCC {$return->code}",
                ]);

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
            foreach ($return->items as $item) {
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $return->warehouse_id,
                    'type'        => 'in',
                    'quantity'    => $item->quantity,
                    'source_type' => PurchaseReturn::class,
                    'source_id'   => $return->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy phiếu trả NCC {$return->code}",
                ]);

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
