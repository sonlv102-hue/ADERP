<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Jobs\NotifyLowStockJob;
use App\Models\JournalEntry;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockExit;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockService
{
    public function __construct(private AccountingService $accounting) {}
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

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');

        // Hạch toán nhập kho: Dr 156 / Cr 331
        $this->postEntryJournal($entry);
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

        Cache::forget('dashboard.stock_overview');
        Cache::forget('dashboard.stats');

        // Hạch toán giá vốn: Dr 632 / Cr 156
        $this->postExitJournal($exit);

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
                $exit->update(['status' => StockExitStatus::Cancelled]);
            });
            return;
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
            // Tạo movement dương để đảo ngược tồn kho (giữ audit trail)
            foreach ($exit->items as $item) {
                StockMovement::create([
                    'product_id'   => $item->product_id,
                    'warehouse_id' => $exit->warehouse_id,
                    'type'         => 'in',
                    'quantity'     => $item->quantity,
                    'source_type'  => StockExit::class,
                    'source_id'    => $exit->id,
                    'created_by'   => auth()->id(),
                    'notes'        => "Hủy phiếu xuất kho {$exit->code}",
                ]);
            }

            // Hoàn serial về InStock
            foreach ($exit->items as $item) {
                foreach ($item->serials as $serial) {
                    $serial->transition(SerialStatus::InStock);
                }
            }

            $exit->update(['status' => StockExitStatus::Cancelled]);
        });

        // Đảo bút toán giá vốn nếu đã hạch toán
        $entry = JournalEntry::where('reference_type', 'stock_exit')
            ->where('reference_id', $exit->id)
            ->where('status', 'posted')
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();

        if ($entry) {
            try {
                $this->accounting->reverse($entry, "Đảo: Hủy phiếu xuất kho {$exit->code}");
            } catch (\Exception $e) {
                \Log::warning("Reverse exit journal failed [{$exit->code}]: " . $e->getMessage());
            }
        }

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
        $lines = [];

        foreach ($entry->items as $item) {
            $unitInclTax = (float) ($item->unit_price ?? $item->product?->cost_price ?? 0);
            $taxRate     = (float) ($item->tax_rate ?? 10);
            $divisor     = 1 + $taxRate / 100;

            $lineInclTax = $unitInclTax * $item->quantity;
            $lineExclTax = $lineInclTax / $divisor;
            $lineTax     = $lineInclTax - $lineExclTax;

            $exclRounded = (int) round($lineExclTax);
            $taxRounded  = (int) round($lineTax);

            if ($exclRounded > 0) {
                $lines[] = ['account' => '156', 'debit' => $exclRounded, 'credit' => 0,
                            'description' => "Nhập kho {$item->product?->name}"];
            }
            if ($taxRounded > 0) {
                $lines[] = ['account' => '1331', 'debit' => $taxRounded, 'credit' => 0,
                            'description' => "Thuế GTGT đầu vào {$item->product?->name}"];
            }
        }

        if (empty($lines)) return;

        // Cr 331 = tổng các dòng Nợ đã round — đảm bảo bút toán luôn cân bằng
        $totalCredit = array_sum(array_column($lines, 'debit'));
        if ($totalCredit <= 0) return;

        $lines[] = ['account' => '331', 'debit' => 0, 'credit' => $totalCredit,
                    'description' => "Phải trả NCC - phiếu {$entry->code}"];

        try {
            $this->accounting->post(
                "Nhập kho hàng hóa {$entry->code}",
                Carbon::parse($entry->entry_date),
                $lines, 'stock_entry', $entry->id, true
            );
        } catch (\Exception $e) {
            \Log::warning("Auto-posting failed [StockEntry {$entry->code}]: " . $e->getMessage());
        }
    }

    private function postExitJournal(StockExit $exit): void
    {
        $exit->load('items.product');
        $totalCogs = 0;
        $lines     = [];

        foreach ($exit->items as $item) {
            // cost_price = giá nhập đã gồm VAT (quy ước dự án); tách ra theo vat_percent trên sản phẩm
            $vatRate     = (float) ($item->product?->vat_percent ?? 10);
            $costInclTax = (float) ($item->product?->cost_price ?? 0);
            $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
            $costExclTax = $costInclTax / $divisor;
            $cogs        = $costExclTax * $item->quantity;
            $totalCogs  += $cogs;

            if ($cogs > 0) {
                $lines[] = ['account' => '632', 'debit' => (int) round($cogs), 'credit' => 0,
                            'description' => "Giá vốn {$item->product?->name}"];
                $lines[] = ['account' => '156', 'debit' => 0, 'credit' => (int) round($cogs),
                            'description' => "Xuất kho {$item->product?->name}"];
            }
        }

        if ($totalCogs <= 0 || empty($lines)) return;

        try {
            $this->accounting->post(
                "Giá vốn hàng bán {$exit->code}",
                Carbon::parse($exit->exit_date),
                $lines, 'stock_exit', $exit->id, true
            );
        } catch (\Exception $e) {
            \Log::warning("Auto-posting failed [StockExit {$exit->code}]: " . $e->getMessage());
        }
    }
}
