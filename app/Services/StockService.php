<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Jobs\NotifyLowStockJob;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    public function confirmEntry(StockEntry $entry): void
    {
        if ($entry->status !== StockEntryStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        // Re-validate against PO to prevent over-receipt from multiple drafts
        if ($entry->purchase_order_id) {
            $po = PurchaseOrder::with('items')->find($entry->purchase_order_id);
            if ($po) {
                $confirmedIds = StockEntry::where('purchase_order_id', $po->id)
                    ->where('status', StockEntryStatus::Confirmed)
                    ->pluck('id');
                $confirmedQtys = StockEntryItem::whereIn('stock_entry_id', $confirmedIds)
                    ->selectRaw('product_id, SUM(quantity) as total')
                    ->groupBy('product_id')
                    ->pluck('total', 'product_id');

                $poItemMap = $po->items->keyBy('product_id');
                $entry->load('items.product');

                foreach ($entry->items as $item) {
                    $poItem = $poItemMap[$item->product_id] ?? null;
                    if (!$poItem) continue;
                    $alreadyConfirmed = (int) ($confirmedQtys[$item->product_id] ?? 0);
                    $total = $alreadyConfirmed + $item->quantity;
                    if ($total > $poItem->quantity) {
                        $over = $total - $poItem->quantity;
                        throw new RuntimeException(
                            "Không thể xác nhận: \"{$item->product->name}\" vượt quá số lượng đơn mua. Đã nhận: {$alreadyConfirmed}, phiếu này: {$item->quantity}, vượt quá: {$over}."
                        );
                    }
                }
            }
        }

        DB::transaction(function () use ($entry) {
            foreach ($entry->items as $item) {
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $entry->warehouse_id,
                    'type' => 'in',
                    'quantity' => $item->quantity,
                    'source_type' => StockEntry::class,
                    'source_id' => $entry->id,
                    'created_by' => auth()->id(),
                    'notes' => "Nhập kho từ phiếu {$entry->code}",
                ]);
            }

            $entry->update(['status' => StockEntryStatus::Confirmed]);
        });
    }

    public function cancelEntry(StockEntry $entry): void
    {
        if ($entry->status === StockEntryStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        if ($entry->status === StockEntryStatus::Draft) {
            $itemIds = $entry->items()->pluck('id');

            // Guard: fail if any serial is already reserved on a StockExit Draft
            $reserved = ProductSerial::whereIn('stock_entry_item_id', $itemIds)
                ->whereNotNull('stock_exit_item_id')
                ->exists();
            if ($reserved) {
                throw new RuntimeException(
                    'Không thể hủy: một số serial đã được gán vào phiếu xuất kho nháp. Vui lòng hủy phiếu xuất trước.'
                );
            }

            DB::transaction(function () use ($entry, $itemIds) {
                ProductSerial::whereIn('stock_entry_item_id', $itemIds)->delete();
                $entry->update(['status' => StockEntryStatus::Cancelled]);
            });
            return;
        }

        // Confirmed: kiểm tra không có serial nào đã rời kho
        $entry->load('items.serials');
        foreach ($entry->items as $item) {
            foreach ($item->serials as $serial) {
                if ($serial->status !== SerialStatus::InStock) {
                    throw new RuntimeException(
                        "Không thể hủy: serial [{$serial->serial_number}] đang ở trạng thái \"{$serial->status->label()}\". Chỉ hủy được khi tất cả serial còn trong kho."
                    );
                }
            }
        }

        DB::transaction(function () use ($entry) {
            // Tạo movement âm để đảo ngược tồn kho (giữ audit trail)
            foreach ($entry->items as $item) {
                StockMovement::create([
                    'product_id'  => $item->product_id,
                    'warehouse_id' => $entry->warehouse_id,
                    'type'        => 'out',
                    'quantity'    => -$item->quantity,
                    'source_type' => StockEntry::class,
                    'source_id'   => $entry->id,
                    'created_by'  => auth()->id(),
                    'notes'       => "Hủy phiếu nhập kho {$entry->code}",
                ]);
            }

            // Chuyển serial → Cancelled
            foreach ($entry->items as $item) {
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::Cancelled);
                }
            }

            $entry->update(['status' => StockEntryStatus::Cancelled]);
        });
    }

    public function confirmExit(StockExit $exit): void
    {
        if ($exit->status !== StockExitStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        DB::transaction(function () use ($exit) {
            $exit->load('items.product', 'items.serials');

            foreach ($exit->items as $item) {
                // Lock các movement rows của sản phẩm này trong kho để tránh race condition
                // khi 2 phiếu xuất cùng sản phẩm được confirm đồng thời
                StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $exit->warehouse_id)
                    ->lockForUpdate()
                    ->get();

                $currentStock = StockMovement::where('product_id', $item->product_id)
                    ->where('warehouse_id', $exit->warehouse_id)
                    ->sum('quantity');

                if ($currentStock < $item->quantity) {
                    throw new RuntimeException(
                        "Sản phẩm [{$item->product->name}] không đủ tồn kho. Hiện có: {$currentStock}, cần: {$item->quantity}."
                    );
                }

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $exit->warehouse_id,
                    'type' => 'out',
                    'quantity' => -$item->quantity,
                    'source_type' => StockExit::class,
                    'source_id' => $exit->id,
                    'created_by' => auth()->id(),
                    'notes' => "Xuất kho từ phiếu {$exit->code}",
                ]);

                // Chuyển trạng thái serial → sold
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::Sold);
                }
            }

            $exit->update(['status' => StockExitStatus::Confirmed]);
        });

        // After transaction: check low stock threshold and dispatch async notification job
        // Dispatch AFTER transaction commit — đảm bảo job chỉ chạy khi dữ liệu đã được lưu
        $threshold = (int) \App\Models\Setting::where('key', 'low_stock_threshold')->value('value') ?: 5;
        $exit->load('items.product');
        foreach ($exit->items as $item) {
            $currentStock = StockMovement::where('product_id', $item->product_id)->sum('quantity');
            if ($currentStock <= $threshold) {
                dispatch(new NotifyLowStockJob($item->product_id, (int) $currentStock));
            }
        }
    }

    public function cancelExit(StockExit $exit): void
    {
        if ($exit->status === StockExitStatus::Confirmed) {
            throw new RuntimeException('Không thể hủy phiếu đã xác nhận.');
        }

        DB::transaction(function () use ($exit) {
            // Trả lại serial về trạng thái chưa gắn
            $exit->load('items');
            foreach ($exit->items as $item) {
                ProductSerial::where('stock_exit_item_id', $item->id)
                    ->update(['stock_exit_item_id' => null]);
            }
            $exit->update(['status' => StockExitStatus::Cancelled]);
        });
    }

    public function getStockQuantity(int $productId, int $warehouseId): int
    {
        return (int) StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }
}
