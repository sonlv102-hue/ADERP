<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\OrderItem;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\StockMovement;
use Illuminate\Console\Command;

class AuditStockExitWarehouseImpactCommand extends Command
{
    protected $signature = 'stock-exits:audit-warehouse-impact
                            {--code= : Mã phiếu xuất (XK-)}';

    protected $description = 'Kiểm tra tác động kho của phiếu xuất: movement, AVCO balance, WIP, order_item.';

    public function handle(): int
    {
        $code = $this->option('code');
        if (! $code) {
            $this->error('Cần truyền --code=XK-xxxx');
            return 1;
        }

        $exit = StockExit::with(['warehouse', 'items.product', 'project', 'order'])
            ->where('code', $code)->first();

        if (! $exit) {
            $this->error("Không tìm thấy phiếu xuất {$code}");
            return 1;
        }

        $this->info("=== Kiểm tra phiếu xuất: {$exit->code} ===");
        $this->line("Kho nguồn : {$exit->warehouse?->name} (ID={$exit->warehouse_id})");
        $this->line("Mục đích  : {$exit->issue_purpose}");
        $this->line("Dự án     : " . ($exit->project?->code ?? '—'));
        $this->line("Đơn hàng  : " . ($exit->order?->code ?? '—'));
        $this->line("Trạng thái: {$exit->status->value}");
        $this->newLine();

        $issues = 0;

        foreach ($exit->items as $item) {
            $productName = $item->product?->name ?? "SP#{$item->product_id}";
            $this->line("--- Sản phẩm: {$productName} (qty={$item->quantity}) ---");

            // 1. Stock movement
            $movements = StockMovement::where('source_type', 'stock_exit')
                ->where('source_id', $exit->id)
                ->where('product_id', $item->product_id)
                ->get();

            if ($movements->isEmpty()) {
                if ($exit->status->value === 'confirmed') {
                    $this->warn("  [WARN] Không có stock_movement cho sản phẩm này");
                    $issues++;
                } else {
                    $this->line("  [OK] Chưa confirmed — chưa có movement (bình thường)");
                }
            } else {
                foreach ($movements as $m) {
                    $whMatch = $m->warehouse_id === $exit->warehouse_id ? 'MATCH' : 'MISMATCH';
                    $status  = $m->warehouse_id === $exit->warehouse_id ? 'OK' : 'ERROR';
                    if ($status === 'ERROR') $issues++;
                    $this->line("  [{$status}] Movement #{$m->id}: warehouse_id={$m->warehouse_id} ({$whMatch} exit.warehouse_id={$exit->warehouse_id}), qty={$m->quantity}, status={$m->status}");
                }
            }

            // 2. AVCO balance
            if ($exit->status->value === 'confirmed') {
                $balance = InventoryBalance::where('product_id', $item->product_id)
                    ->where('warehouse_id', $exit->warehouse_id)
                    ->first();
                if ($balance) {
                    $this->line("  [OK] InventoryBalance tại kho xuất: qty_on_hand={$balance->qty_on_hand}, avg_cost={$balance->avg_cost}");
                } else {
                    $this->warn("  [WARN] Không tìm thấy inventory_balance tại kho nguồn (sản phẩm chưa init AVCO?)");
                }
            }

            // 3. Project WIP
            if (in_array($exit->issue_purpose, ['project_cost']) && $exit->project_id) {
                $wip = ProjectWipEntry::where('source_type', 'stock_exit_item')
                    ->where('source_item_id', $item->id)
                    ->first();
                if ($wip) {
                    $this->line("  [OK] ProjectWipEntry #{$wip->id}: amount={$wip->amount}, status={$wip->status}");
                } else {
                    if ($exit->status->value === 'confirmed') {
                        $this->warn("  [WARN] Thiếu project_wip_entry cho item này (issue_purpose=project_cost)");
                        $issues++;
                    }
                }
            }

            // 4. order_item delivered_quantity
            if ($item->order_item_id) {
                $oi = OrderItem::find($item->order_item_id);
                if ($oi) {
                    $this->line("  [OK] OrderItem #{$oi->id}: delivered_quantity={$oi->delivered_quantity}/{$oi->quantity}");
                } else {
                    $this->warn("  [WARN] order_item_id={$item->order_item_id} không tìm thấy (orphan FK)");
                    $issues++;
                }
            } elseif ($exit->order_id) {
                $this->warn("  [WARN] exit.order_id={$exit->order_id} nhưng exit_item.order_item_id=null (sẽ fallback product_id match trong syncDelivery)");
            }

            $this->newLine();
        }

        if ($issues === 0) {
            $this->info('Không phát hiện vấn đề.');
        } else {
            $this->error("Phát hiện {$issues} vấn đề. Cần kiểm tra thủ công.");
        }

        return $issues > 0 ? 1 : 0;
    }
}
