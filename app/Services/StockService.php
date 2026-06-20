<?php

namespace App\Services;

use App\Enums\ItemUsageType;
use App\Services\AccountingSettings;
use App\Enums\SerialStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Jobs\NotifyLowStockJob;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockExitItemLotAllocation;
use App\Models\StockMovement;
use App\Services\AvcoService;
use App\Services\ProjectWipService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    public function __construct(
        private AccountingService $accounting,
        private ProjectWipService $wip,
        private AvcoService $avco,
    ) {}
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
            $entry->load('items.product');
            $po = $entry->purchase_order_id
                ? PurchaseOrder::find($entry->purchase_order_id)
                : null;

            foreach ($entry->items as $item) {
                // Resolve project_id: item → PO line → PO header
                $projectId = $item->project_id;
                if (!$projectId && $item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::lockForUpdate()->find($item->purchase_order_item_id);
                    if ($poItem) {
                        $projectId = $poItem->project_id;
                        // Validate per PO line + update received_quantity
                        if ($poItem->received_quantity + $item->quantity > $poItem->quantity) {
                            throw new RuntimeException(
                                "Vượt số lượng đơn mua tại dòng \"{$item->product?->name}\": đã nhận {$poItem->received_quantity}, phiếu này {$item->quantity}, tối đa {$poItem->quantity}."
                            );
                        }
                        $poItem->increment('received_quantity', $item->quantity);
                    }
                }
                if (!$projectId) {
                    $projectId = $po?->project_id;
                }

                // unit_cost = unit_price excl VAT (business rule: unit_price IS excl VAT)
                $unitCost = (float) ($item->unit_cost ?? $item->unit_price ?? 0);

                // Persist project_id and unit_cost on item
                $item->update([
                    'project_id' => $projectId,
                    'unit_cost'  => $unitCost,
                ]);

                $movement = StockMovement::create([
                    'product_id'     => $item->product_id,
                    'warehouse_id'   => $entry->warehouse_id,
                    'type'           => 'in',
                    'quantity'       => (int) $item->quantity,
                    'source_type'    => StockEntry::class,
                    'source_id'      => $entry->id,
                    'source_item_id' => $item->id,
                    'created_by'     => auth()->id(),
                    'notes'          => "Nhập kho từ phiếu {$entry->code}",
                    'project_id'     => $projectId,
                    'unit_cost'      => $unitCost,
                    'amount'         => $unitCost * (float) $item->quantity,
                ]);

                // Cập nhật AVCO balance cho hàng không thuộc dự án
                if (!$projectId) {
                    $this->avco->recordEntry(
                        $item->product_id,
                        $entry->warehouse_id,
                        (float) $item->quantity,
                        $unitCost,
                        $movement->id,
                    );
                }

                // Tạo project inventory lot nếu hàng thuộc dự án
                if ($projectId) {
                    ProjectInventoryLot::create([
                        'project_id'             => $projectId,
                        'product_id'             => $item->product_id,
                        'warehouse_id'           => $entry->warehouse_id,
                        'stock_entry_id'         => $entry->id,
                        'stock_entry_item_id'    => $item->id,
                        'purchase_order_id'      => $entry->purchase_order_id,
                        'purchase_order_item_id' => $item->purchase_order_item_id,
                        'received_qty'           => $item->quantity,
                        'issued_qty'             => 0,
                        'unit_cost'              => $unitCost,
                        'received_at'            => $entry->entry_date ?? now(),
                        'status'                 => 'active',
                    ]);
                }
            }

            $entry->update(['status' => StockEntryStatus::Confirmed]);

            // Hạch toán nhập kho trong cùng transaction — nếu JE fail, toàn bộ rollback
            $this->postEntryJournal($entry);
        });

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');
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

        // Guard: project inventory lots không còn allocation active (issued_qty > 0 nghĩa là exit chưa hủy)
        $hasActiveLots = ProjectInventoryLot::where('stock_entry_id', $entry->id)
            ->where('issued_qty', '>', 0)
            ->exists();
        if ($hasActiveLots) {
            throw new RuntimeException(
                'Không thể hủy: hàng hóa từ phiếu nhập này đã được phân bổ cho phiếu xuất dự án. Vui lòng hủy phiếu xuất trước.'
            );
        }

        DB::transaction(function () use ($entry) {
            // Tạo movement âm để đảo ngược tồn kho (giữ audit trail)
            foreach ($entry->items as $item) {
                // Hoàn lại số lượng đã nhận trên đơn mua
                if ($item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::lockForUpdate()->find($item->purchase_order_item_id);
                    if ($poItem) {
                        $newQty = max(0, (float)$poItem->received_quantity - (float)$item->quantity);
                        $poItem->update(['received_quantity' => $newQty]);
                    }
                }

                $cancelCost = (float) ($item->unit_cost ?? $item->unit_price ?? 0);
                StockMovement::create([
                    'product_id'     => $item->product_id,
                    'warehouse_id'   => $entry->warehouse_id,
                    'type'           => 'out',
                    'quantity'       => -(int) $item->quantity,
                    'source_type'    => StockEntry::class,
                    'source_id'      => $entry->id,
                    'source_item_id' => $item->id,
                    'created_by'     => auth()->id(),
                    'notes'          => "Hủy phiếu nhập kho {$entry->code}",
                    'project_id'     => $item->project_id,
                    'unit_cost'      => $cancelCost,
                    'amount'         => -($cancelCost * (float) $item->quantity),
                ]);

                // Đảo ngược AVCO balance cho hàng không thuộc dự án (chỉ nếu balance đã tồn tại)
                if (!$item->project_id && $this->avco->getBalance($item->product_id, $entry->warehouse_id) !== null) {
                    $this->avco->recordExit(
                        $item->product_id,
                        $entry->warehouse_id,
                        (float) $item->quantity,
                    );
                }
            }

            // Chuyển serial → Cancelled
            foreach ($entry->items as $item) {
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::Cancelled);
                }
            }

            // Đánh dấu project inventory lots là cancelled
            ProjectInventoryLot::where('stock_entry_id', $entry->id)->update(['status' => 'cancelled']);

            $this->accounting->reverseOrDelete('stock_entry', $entry->id, "Hủy phiếu nhập kho {$entry->code}");
            $entry->update(['status' => StockEntryStatus::Cancelled]);
        });

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');
    }

    public function recallEntry(StockEntry $entry): void
    {
        if ($entry->status !== StockEntryStatus::Confirmed) {
            throw new RuntimeException('Chỉ có thể thu hồi phiếu đã xác nhận.');
        }

        // Kiểm tra không có serial nào đã rời kho
        $entry->load('items.serials');
        foreach ($entry->items as $item) {
            foreach ($item->serials as $serial) {
                if ($serial->status !== SerialStatus::InStock) {
                    throw new RuntimeException(
                        "Không thể thu hồi: serial [{$serial->serial_number}] đang ở trạng thái \"{$serial->status->label()}\". Chỉ thu hồi được khi tất cả serial còn trong kho."
                    );
                }
            }
        }

        DB::transaction(function () use ($entry) {
            $this->accounting->reverseOrDelete('stock_entry', $entry->id, "Thu hồi phiếu nhập kho {$entry->code} để chỉnh sửa");

            // Tạo movement âm để đảo ngược tồn kho tạm thời (sẽ được tạo lại khi confirm)
            foreach ($entry->items as $item) {
                // Hoàn lại số lượng đã nhận trên đơn mua
                if ($item->purchase_order_item_id) {
                    $poItem = PurchaseOrderItem::lockForUpdate()->find($item->purchase_order_item_id);
                    if ($poItem) {
                        $newQty = max(0, (float)$poItem->received_quantity - (float)$item->quantity);
                        $poItem->update(['received_quantity' => $newQty]);
                    }
                }

                $recallCost = (float) ($item->unit_cost ?? $item->unit_price ?? 0);
                StockMovement::create([
                    'product_id'     => $item->product_id,
                    'warehouse_id'   => $entry->warehouse_id,
                    'type'           => 'out',
                    'quantity'       => -(int) $item->quantity,
                    'source_type'    => StockEntry::class,
                    'source_id'      => $entry->id,
                    'source_item_id' => $item->id,
                    'created_by'     => auth()->id(),
                    'notes'          => "Thu hồi phiếu nhập kho {$entry->code} để chỉnh sửa",
                    'project_id'     => $item->project_id,
                    'unit_cost'      => $recallCost,
                    'amount'         => -($recallCost * (float) $item->quantity),
                ]);

                // Đảo ngược AVCO balance tạm thời (sẽ được cộng lại khi confirm lại)
                if (!$item->project_id && $this->avco->getBalance($item->product_id, $entry->warehouse_id) !== null) {
                    $this->avco->recordExit(
                        $item->product_id,
                        $entry->warehouse_id,
                        (float) $item->quantity,
                    );
                }
            }

            // Serial giữ nguyên InStock (hàng vẫn đang trong kho vật lý)
            $entry->update(['status' => StockEntryStatus::Draft]);
        });

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');
    }

    public function confirmExit(StockExit $exit): void
    {
        if ($exit->status !== StockExitStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        DB::transaction(function () use ($exit) {
            $exit->load('items.product', 'items.serials');

            foreach ($exit->items as $item) {
                $qty = (int) $item->quantity;

                if ($this->isProjectScopedExit($exit)) {
                    // ── Project-scoped: FIFO từ project_inventory_lots ──────────────────
                    $lots = ProjectInventoryLot::where('project_id', $exit->project_id)
                        ->where('product_id', $item->product_id)
                        ->where('warehouse_id', $exit->warehouse_id)
                        ->where('status', 'active')
                        ->whereRaw('issued_qty < received_qty')
                        ->orderBy('received_at', 'ASC')
                        ->lockForUpdate()
                        ->get();

                    $available = $lots->sum(fn($l) => (float)$l->received_qty - (float)$l->issued_qty);
                    if ($available < $qty) {
                        throw new RuntimeException(
                            "Sản phẩm [{$item->product->name}]: tồn kho dự án chỉ còn {$available} đơn vị, cần {$qty}."
                        );
                    }

                    $remaining = $qty;
                    $totalCost = 0.0;
                    foreach ($lots as $lot) {
                        if ($remaining <= 0) break;
                        $lotAvail = (float)$lot->received_qty - (float)$lot->issued_qty;
                        $allocQty = min($lotAvail, $remaining);
                        $allocAmt = $allocQty * (float)$lot->unit_cost;

                        StockExitItemLotAllocation::create([
                            'stock_exit_id'            => $exit->id,
                            'stock_exit_item_id'       => $item->id,
                            'project_inventory_lot_id' => $lot->id,
                            'project_id'               => $exit->project_id,
                            'product_id'               => $item->product_id,
                            'warehouse_id'             => $exit->warehouse_id,
                            'allocated_qty'            => $allocQty,
                            'unit_cost'                => $lot->unit_cost,
                            'amount'                   => $allocAmt,
                        ]);

                        $lot->issued_qty = (float)$lot->issued_qty + $allocQty;
                        if ($lot->issued_qty >= (float)$lot->received_qty) {
                            $lot->status = 'depleted';
                        }
                        $lot->save();

                        $totalCost += $allocAmt;
                        $remaining -= $allocQty;
                    }

                    $sourceCost = $qty > 0 ? $totalCost / $qty : 0;
                    $item->update([
                        'project_id'  => $exit->project_id,
                        'source_cost' => $sourceCost,
                        'total_cost'  => $totalCost,
                        'cost_source' => 'fifo',
                    ]);

                    StockMovement::create([
                        'product_id'     => $item->product_id,
                        'warehouse_id'   => $exit->warehouse_id,
                        'type'           => 'out',
                        'quantity'       => -$qty,
                        'source_type'    => StockExit::class,
                        'source_id'      => $exit->id,
                        'source_item_id' => $item->id,
                        'created_by'     => auth()->id(),
                        'notes'          => "Xuất kho dự án từ phiếu {$exit->code}",
                        'project_id'     => $exit->project_id,
                        'unit_cost'      => $sourceCost,
                        'amount'         => -$totalCost,
                    ]);
                } else {
                    // ── Non-project: kiểm tra tổng kho warehouse ───────────────────────
                    StockMovement::where('product_id', $item->product_id)
                        ->where('warehouse_id', $exit->warehouse_id)
                        ->lockForUpdate()
                        ->get();

                    $currentStock = StockMovement::where('product_id', $item->product_id)
                        ->where('warehouse_id', $exit->warehouse_id)
                        ->sum('quantity');

                    if ($currentStock < $qty) {
                        throw new RuntimeException(
                            "Sản phẩm [{$item->product->name}] không đủ tồn kho. Hiện có: {$currentStock}, cần: {$qty}."
                        );
                    }

                    // Lấy giá bình quân gia quyền AVCO (cũng cập nhật inventory_balances)
                    $avgCost   = $this->avco->recordExit(
                        $item->product_id,
                        $exit->warehouse_id,
                        (float) $qty,
                    );
                    $totalCost = $avgCost * (float) $qty;

                    $item->update([
                        'source_cost' => $avgCost,
                        'total_cost'  => $totalCost,
                        'cost_source' => 'avco',
                    ]);

                    StockMovement::create([
                        'product_id'     => $item->product_id,
                        'warehouse_id'   => $exit->warehouse_id,
                        'type'           => 'out',
                        'quantity'       => -$qty,
                        'source_type'    => StockExit::class,
                        'source_id'      => $exit->id,
                        'source_item_id' => $item->id,
                        'created_by'     => auth()->id(),
                        'notes'          => "Xuất kho từ phiếu {$exit->code}",
                        'project_id'     => $exit->project_id,
                        'unit_cost'      => $avgCost,
                        'amount'         => -$totalCost,
                    ]);
                }

                // Chuyển trạng thái serial → sold
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::Sold);
                }
            }

            $exit->update(['status' => StockExitStatus::Confirmed]);

            // Hạch toán xuất kho trong cùng transaction — nếu JE fail, toàn bộ rollback
            $this->postExitJournal($exit);
        });

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');

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
        if ($exit->status === StockExitStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        if ($exit->status === StockExitStatus::Draft) {
            DB::transaction(function () use ($exit) {
                $exit->load('items');
                foreach ($exit->items as $item) {
                    ProductSerial::where('stock_exit_item_id', $item->id)
                        ->update(['stock_exit_item_id' => null]);
                }
                \App\Models\ProjectWipEntry::where('source_type', StockExit::class)
                    ->where('source_id', $exit->id)
                    ->delete();
                $exit->update(['status' => StockExitStatus::Cancelled]);
            });
            return;
        }

        // Confirmed: kiểm tra đơn hàng liên kết có hóa đơn chưa hủy không
        if ($exit->order_id) {
            $hasActiveInvoice = \App\Models\Invoice::where('order_id', $exit->order_id)
                ->whereIn('status', ['sent', 'paid', 'overdue'])
                ->exists();
            if ($hasActiveInvoice) {
                throw new RuntimeException(
                    "Không thể hủy phiếu xuất: đơn hàng đã có hóa đơn. Hủy hóa đơn trước rồi mới hủy phiếu xuất."
                );
            }
        }

        // Confirmed: kiểm tra serial chưa bị chuyển trạng thái thêm
        $exit->load('items.serials');
        foreach ($exit->items as $item) {
            foreach ($item->serials as $serial) {
                if ($serial->status !== SerialStatus::Sold) {
                    throw new RuntimeException(
                        "Không thể hủy: serial [{$serial->serial_number}] đang ở trạng thái \"{$serial->status->label()}\", không thể hoàn về kho."
                    );
                }
            }
        }

        DB::transaction(function () use ($exit) {
            // Null out WIP journal_entry_id references before reversing JE (FK constraint)
            \App\Models\ProjectWipEntry::where('source_type', StockExit::class)
                ->where('source_id', $exit->id)
                ->update(['journal_entry_id' => null]);

            $this->accounting->reverseOrDelete('stock_exit', $exit->id, "Hủy phiếu xuất kho {$exit->code}");

            // Void lot allocations và hoàn issued_qty
            $allocations = StockExitItemLotAllocation::where('stock_exit_id', $exit->id)
                ->whereNull('voided_at')
                ->get();

            foreach ($allocations as $alloc) {
                $lot = ProjectInventoryLot::lockForUpdate()->find($alloc->project_inventory_lot_id);
                if ($lot) {
                    $lot->issued_qty = max(0, (float)$lot->issued_qty - (float)$alloc->allocated_qty);
                    if ($lot->status === 'depleted' && $lot->issued_qty < (float)$lot->received_qty) {
                        $lot->status = 'active';
                    }
                    $lot->save();
                }
                $alloc->update(['voided_at' => now()]);
            }

            // Tạo movement dương để đảo ngược tồn kho (giữ audit trail)
            foreach ($exit->items as $item) {
                StockMovement::create([
                    'product_id'     => $item->product_id,
                    'warehouse_id'   => $exit->warehouse_id,
                    'type'           => 'in',
                    'quantity'       => (int) $item->quantity,
                    'source_type'    => StockExit::class,
                    'source_id'      => $exit->id,
                    'source_item_id' => $item->id,
                    'created_by'     => auth()->id(),
                    'notes'          => "Hủy phiếu xuất kho {$exit->code}",
                    'project_id'     => $item->project_id,
                    'unit_cost'      => $item->source_cost,
                    'amount'         => $item->total_cost,
                ]);

                // Khôi phục AVCO balance cho phiếu non-project đã dùng AVCO
                if ($item->cost_source === 'avco' && !$item->project_id) {
                    $this->avco->recordEntry(
                        $item->product_id,
                        $exit->warehouse_id,
                        (float) $item->quantity,
                        (float) ($item->source_cost ?? 0),
                    );
                }
            }

            // Hoàn serial về InStock
            foreach ($exit->items as $item) {
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::InStock);
                }
            }

            // Xóa WIP entries nếu có
            \App\Models\ProjectWipEntry::where('source_type', StockExit::class)
                ->where('source_id', $exit->id)
                ->delete();

            $exit->update(['status' => StockExitStatus::Cancelled]);
        });

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');
    }

    public function getStockQuantity(int $productId, int $warehouseId): int
    {
        return (int) StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }

    // ─── Auto-posting helpers ────────────────────────────────────────────────

    private function postEntryJournal(StockEntry $entry): void
    {
        $entry->load('items.product');
        $supplierId = $entry->purchase_order_id
            ? DB::table('purchase_orders')->where('id', $entry->purchase_order_id)->value('supplier_id')
            : null;
        $supplier = $supplierId ? \App\Models\Supplier::find($supplierId) : null;
        $lines = [];

        foreach ($entry->items as $item) {
            $unitExclTax = (float) ($item->unit_price ?? $item->product?->cost_price ?? 0);
            $taxRate     = (float) ($item->tax_rate ?? 10);

            $lineExclTax = $unitExclTax * $item->quantity;
            $lineTax     = $lineExclTax * $taxRate / 100;
            $lineInclTax = $lineExclTax + $lineTax;

            $exclRounded = (int) round($lineExclTax);
            $taxRounded  = (int) round($lineTax);

            if ($exclRounded > 0) {
                $lines[] = ['account' => $this->postEntryInventoryAccount($item), 'debit' => $exclRounded, 'credit' => 0,
                            'description' => "Nhập kho {$item->product?->name}"];
            }
            if ($taxRounded > 0) {
                $lines[] = ['account' => AccountingSettings::get('vat_input_account', '1331'), 'debit' => $taxRounded, 'credit' => 0,
                            'description' => "Thuế GTGT đầu vào {$item->product?->name}"];
            }
        }

        if (empty($lines)) return;

        // Cr TK phải trả NCC = tổng các dòng Nợ đã round — đảm bảo bút toán luôn cân bằng
        $totalCredit = array_sum(array_column($lines, 'debit'));
        if ($totalCredit <= 0) return;

        if (!$supplier) {
            throw new \RuntimeException(
                "Phiếu nhập kho {$entry->code} không xác định được nhà cung cấp. Không thể tạo bút toán."
            );
        }
        $lines[] = ['account' => $supplier->getPayableAccount(), 'debit' => 0, 'credit' => $totalCredit,
                    'description'  => "Phải trả NCC - phiếu {$entry->code}",
                    'partner_type' => 'supplier', 'partner_id' => $supplierId];

        $this->accounting->tryPost(
            "Nhập kho hàng hóa {$entry->code}",
            Carbon::parse($entry->entry_date),
            $lines, 'stock_entry', $entry->id, 'inbound'
        );
    }

    private function postEntryInventoryAccount(StockEntryItem $item): string
    {
        // Ưu tiên line_type từ PO item nếu có
        if ($item->purchase_order_item_id) {
            $lineType = \App\Models\PurchaseOrderItem::where('id', $item->purchase_order_item_id)
                ->value('line_type');
            $mapped = match($lineType) {
                'material'    => '1521',
                'tool'        => '1531',
                'fixed_asset' => AccountingSettings::get('fixed_asset_account', '2111'),
                default       => null, // goods → dùng product.inventory_account bên dưới
            };
            if ($mapped !== null) return $mapped;
        }

        $product = $item->product ?? Product::find($item->product_id);
        return $product?->inventory_account
            ?? AccountingSettings::get('default_inventory_account', '1561');
    }

    private function resolveInventoryAccount(int $productId, StockExit $exit): string
    {
        $product = Product::find($productId);
        return $product?->inventory_account
            ?? $exit->inventory_account
            ?? AccountingSettings::get('default_inventory_account', '1561');
    }

    private function resolveDebitAccount(StockExit $exit): string
    {
        if ($exit->cost_account) {
            return $exit->cost_account;
        }

        $purpose = $exit->issue_purpose;

        // Backward compat: nếu chưa set issue_purpose thì dùng item_usage_type
        if (!$purpose) {
            return $exit->item_usage_type === ItemUsageType::Project
                ? AccountingSettings::get('project_wip_account', '154')
                : AccountingSettings::get('default_cogs_account', '632');
        }

        return match($purpose) {
            'project_cost'    => AccountingSettings::get('project_wip_account', '154'),
            'sale_delivery'   => AccountingSettings::get('default_cogs_account', '632'),
            'selling_expense' => AccountingSettings::get('selling_expense_account', '6421'),
            'admin_expense'   => AccountingSettings::get('admin_expense_account', '6422'),
            'internal_use'    => throw new RuntimeException(
                "Xuất kho mục đích internal_use phải cấu hình cost_account."
            ),
            default => throw new RuntimeException("issue_purpose '{$purpose}' không hợp lệ."),
        };
    }

    private function isProjectScopedExit(StockExit $exit): bool
    {
        return $exit->issue_purpose === 'project_cost'
            || $exit->item_usage_type === ItemUsageType::Project;
    }

    private function postExitJournal(StockExit $exit): void
    {
        $exit->load('items.product');

        $debitAccount = $this->resolveDebitAccount($exit);
        $isProject    = $this->isProjectScopedExit($exit);
        $projectId    = $isProject ? $exit->project_id : null;

        $totalCogs = 0;
        $lines     = [];

        foreach ($exit->items as $item) {
            // Ưu tiên dùng FIFO cost đã tính, fallback về product cost_price
            if ($item->total_cost !== null && (float)$item->total_cost > 0) {
                $cogs = (float) $item->total_cost;
            } else {
                $vatRate     = (float) ($item->product?->vat_percent ?? 10);
                $costInclTax = (float) ($item->product?->cost_price ?? 0);
                $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
                $cogs        = ($costInclTax / $divisor) * (float)$item->quantity;
            }
            $totalCogs += $cogs;

            $inventoryAccount = $this->resolveInventoryAccount($item->product_id, $exit);

            if ($cogs > 0) {
                $lines[] = [
                    'account'     => $debitAccount,
                    'debit'       => (int) round($cogs),
                    'credit'      => 0,
                    'description' => $isProject
                        ? "CP vật tư dự án {$item->product?->name}"
                        : "Giá vốn {$item->product?->name}",
                    'project_id'  => $projectId,
                ];
                $lines[] = [
                    'account'     => $inventoryAccount,
                    'debit'       => 0,
                    'credit'      => (int) round($cogs),
                    'description' => "Xuất kho {$item->product?->name}",
                    'project_id'  => $projectId,
                ];
            }
        }

        if ($totalCogs <= 0 || empty($lines)) return;

        $description = match($exit->issue_purpose) {
            'project_cost'    => "Xuất vật tư dự án {$exit->code}",
            'selling_expense' => "Chi phí bán hàng {$exit->code}",
            'admin_expense'   => "Chi phí QLDN {$exit->code}",
            default           => $isProject
                ? "Xuất vật tư dự án {$exit->code}"
                : "Giá vốn hàng bán {$exit->code}",
        };

        $journalEntry = $this->accounting->tryPost(
            $description,
            Carbon::parse($exit->exit_date),
            $lines, 'stock_exit', $exit->id, 'outbound'
        );

        // Tạo WIP entry nếu xuất cho dự án
        if ($isProject && $journalEntry) {
            $exit->load('items');
            foreach ($exit->items as $item) {
                $this->wip->createFromStockExitItem($exit, $item, $journalEntry->id);
            }
        }
    }
}
