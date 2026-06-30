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
    public function __construct(private AvcoService $avco) {}

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
            foreach ($transfer->items as $item) {
                $currentStock = StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                    ->sum('quantity');

                if ($currentStock < $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}] không đủ tồn kho tại kho nguồn. Hiện có: {$currentStock}, cần: {$item->quantity}."
                    );
                }

                if ($item->product->has_serial) {
                    $selectedSerials = $item->serials;
                    if ($selectedSerials->count() !== $item->quantity) {
                        throw new RuntimeException(
                            "Sản phẩm [{$item->product->name}] cần chọn đúng {$item->quantity} serial để chuyển kho."
                        );
                    }
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
                $qty = (float) $item->quantity;

                // Lấy avg_cost và giảm AVCO kho nguồn
                $avgCost = $this->avco->recordExit(
                    $item->product_id, $transfer->from_warehouse_id, $qty
                );

                // Movement OUT tại kho nguồn
                StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type'         => 'out',
                    'quantity'     => -(int) $item->quantity,
                    'unit_cost'    => $avgCost,
                    'amount'       => -($avgCost * $qty),
                    'status'       => 'active',
                    'source_type'  => StockTransfer::class,
                    'source_id'    => $transfer->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Chuyển kho phiếu {$transfer->code} → {$toWarehouseName}",
                ]);

                // Movement IN tại kho đích
                $inMovement = StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'type'         => 'in',
                    'quantity'     => (int) $item->quantity,
                    'unit_cost'    => $avgCost,
                    'amount'       => $avgCost * $qty,
                    'status'       => 'active',
                    'source_type'  => StockTransfer::class,
                    'source_id'    => $transfer->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Nhận từ chuyển kho phiếu {$transfer->code}",
                ]);

                // Tăng AVCO kho đích với cùng avg_cost từ kho nguồn (giữ nguyên tổng giá trị)
                $this->avco->recordEntry(
                    $item->product_id, $transfer->to_warehouse_id, $qty, $avgCost, $inMovement->id
                );

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

        $transfer->load('items.product', 'items.serials', 'fromWarehouse');

        DB::transaction(function () use ($transfer) {
            $fromWarehouseName = $transfer->fromWarehouse->name;

            foreach ($transfer->items as $item) {
                $destStock = StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->to_warehouse_id)
                    ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                    ->lockForUpdate()
                    ->sum('quantity');

                if ($destStock < $item->quantity) {
                    throw new RuntimeException(
                        "Không thể hủy chuyển kho: sản phẩm [{$item->product->name}] tại kho đích chỉ còn {$destStock} đơn vị (đã xuất bớt). Hủy các phiếu xuất liên quan tại kho đích trước."
                    );
                }
            }

            foreach ($transfer->items as $item) {
                $qty = (float) $item->quantity;

                // Nếu dest đã có AVCO balance (transfer được confirm sau khi có fix),
                // cần reverse AVCO cả 2 chiều để giữ tổng giá trị toàn hệ thống.
                $destBalance = $this->avco->getBalance($item->product_id, $transfer->to_warehouse_id);
                $avgCostForReversal = 0.0;

                if ($destBalance !== null) {
                    $avgCostForReversal = $this->avco->recordExit(
                        $item->product_id, $transfer->to_warehouse_id, $qty
                    );
                }

                // IN reversal tại kho nguồn
                $inMovement = StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type'         => 'in',
                    'quantity'     => (int) $item->quantity,
                    'unit_cost'    => $avgCostForReversal,
                    'amount'       => $avgCostForReversal * $qty,
                    'status'       => 'active',
                    'source_type'  => StockTransfer::class,
                    'source_id'    => $transfer->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Hủy chuyển kho phiếu {$transfer->code} — hoàn kho về {$fromWarehouseName}",
                ]);

                // OUT reversal tại kho đích
                StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'type'         => 'out',
                    'quantity'     => -(int) $item->quantity,
                    'unit_cost'    => $avgCostForReversal,
                    'amount'       => -($avgCostForReversal * $qty),
                    'status'       => 'active',
                    'source_type'  => StockTransfer::class,
                    'source_id'    => $transfer->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Hủy chuyển kho phiếu {$transfer->code} — trả lại kho nguồn",
                ]);

                if ($destBalance !== null) {
                    // Restore AVCO tại kho nguồn với cùng giá trị đã lấy ra
                    $this->avco->recordEntry(
                        $item->product_id, $transfer->from_warehouse_id, $qty, $avgCostForReversal, $inMovement->id
                    );
                }

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
