<?php

namespace App\Services;

use App\Enums\StockTransferStatus;
use App\Models\ProductSerial;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferService
{
    public function confirmTransfer(StockTransfer $transfer): void
    {
        if ($transfer->status !== StockTransferStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        if ($transfer->from_warehouse_id === $transfer->to_warehouse_id) {
            throw new RuntimeException('Kho nguồn và kho đích không được trùng nhau.');
        }

        $transfer->load('items.product', 'items.serials', 'toWarehouse');

        DB::transaction(function () use ($transfer) {
            // Validate stock and serials inside transaction to prevent TOCTOU races
            foreach ($transfer->items as $item) {
                $currentStock = StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->sum('quantity');

                if ($currentStock < $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}] không đủ tồn kho tại kho nguồn. Hiện có: {$currentStock}, cần: {$item->quantity}."
                    );
                }

                // Validate serial-tracked products
                if ($item->product->has_serial) {
                    $selectedSerials = $item->serials;
                    if ($selectedSerials->count() !== $item->quantity) {
                        throw new RuntimeException(
                            "Sản phẩm [{$item->product->name}] cần chọn đúng {$item->quantity} serial để chuyển kho."
                        );
                    }
                    // Verify all selected serials are in the source warehouse
                    foreach ($selectedSerials as $serial) {
                        if ($serial->warehouse_id !== $transfer->from_warehouse_id) {
                            throw new RuntimeException(
                                "Serial [{$serial->serial_number}] không thuộc kho nguồn."
                            );
                        }
                    }
                }
            }

            $toWarehouseName = $transfer->toWarehouse->name;

            foreach ($transfer->items as $item) {
                // Movement: out from source warehouse
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -(int) $item->quantity,
                    'source_type' => StockTransfer::class,
                    'source_id'   => $transfer->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Chuyển kho phiếu {$transfer->code} → {$toWarehouseName}",
                ]);

                // Movement: in to destination warehouse
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'type'        => 'in',
                    'quantity'    => (int) $item->quantity,
                    'source_type' => StockTransfer::class,
                    'source_id'   => $transfer->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Nhận từ chuyển kho phiếu {$transfer->code}",
                ]);

                // Move serials: update warehouse_id and link to transfer item
                if ($item->product->has_serial) {
                    foreach ($item->serials as $serial) {
                        $serial->update([
                            'warehouse_id'           => $transfer->to_warehouse_id,
                            'stock_transfer_item_id' => $item->id,
                        ]);
                    }
                }
            }

            $transfer->update(['status' => StockTransferStatus::Confirmed]);
        });
    }

    public function cancelTransfer(StockTransfer $transfer): void
    {
        if ($transfer->status === StockTransferStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        if ($transfer->status === StockTransferStatus::Draft) {
            $transfer->update(['status' => StockTransferStatus::Cancelled]);
            return;
        }

        // Confirmed: create reversal movements and move serials back
        $transfer->load('items.product', 'items.serials', 'fromWarehouse');

        DB::transaction(function () use ($transfer) {
            $fromWarehouseName = $transfer->fromWarehouse->name;

            foreach ($transfer->items as $item) {
                // Reversal: in back to source warehouse
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type'        => 'in',
                    'quantity'    => (int) $item->quantity,
                    'source_type' => StockTransfer::class,
                    'source_id'   => $transfer->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy chuyển kho phiếu {$transfer->code} — hoàn kho về {$fromWarehouseName}",
                ]);

                // Reversal: out from destination warehouse
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -(int) $item->quantity,
                    'source_type' => StockTransfer::class,
                    'source_id'   => $transfer->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy chuyển kho phiếu {$transfer->code} — trả lại kho nguồn",
                ]);

                // Move serials back to source warehouse
                if ($item->product->has_serial) {
                    foreach ($item->serials as $serial) {
                        $serial->update([
                            'warehouse_id'           => $transfer->from_warehouse_id,
                            'stock_transfer_item_id' => null,
                        ]);
                    }
                }
            }

            $transfer->update(['status' => StockTransferStatus::Cancelled]);
        });
    }
}
