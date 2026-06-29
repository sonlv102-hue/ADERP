<?php

namespace App\Console\Commands;

use App\Models\StockExit;
use App\Models\StockMovement;
use App\Models\ProjectWipEntry;
use Illuminate\Console\Command;

class AuditStockExitLinkageCommand extends Command
{
    protected $signature = 'stock-exits:audit-linkage
                            {--code= : Mã phiếu xuất (XK-)}';

    protected $description = 'Kiểm tra đầy đủ liên kết của phiếu xuất kho: order_id, order_item_id, movement, wip_entry.';

    public function handle(): int
    {
        $code = $this->option('code');
        if (! $code) {
            $this->error('Vui lòng cung cấp --code=XK-xxx');
            return 1;
        }

        $exit = StockExit::with(['items.product', 'order', 'project'])
            ->where('code', $code)
            ->first();

        if (! $exit) {
            $this->error("Không tìm thấy phiếu xuất {$code}.");
            return 1;
        }

        $this->newLine();
        $this->info("═══════════════════════════════════════════════════");
        $this->info("AUDIT PHIẾU XUẤT KHO: {$exit->code}");
        $this->info("═══════════════════════════════════════════════════");

        $this->table(['Field', 'Value'], [
            ['id', $exit->id],
            ['code', $exit->code],
            ['status', $exit->status],
            ['issue_purpose', $exit->issue_purpose ?? 'null'],
            ['warehouse_id', $exit->warehouse_id ?? 'null'],
            ['customer_id', $exit->customer_id ?? 'null'],
            ['order_id', $exit->order_id ? "{$exit->order_id} ({$exit->order?->code})" : 'NULL ⚠'],
            ['project_id', $exit->project_id ? "{$exit->project_id} ({$exit->project?->code})" : 'null'],
            ['journal_entry_id', $exit->journal_entry_id ? "{$exit->journal_entry_id} (BT-)" : 'null'],
            ['exit_date', $exit->exit_date ?? 'null'],
            ['created_at', $exit->created_at],
        ]);

        $this->newLine();
        $this->info("ITEMS:");

        foreach ($exit->items as $ei) {
            $movement = StockMovement::where('source_type', 'App\\Models\\StockExit')
                ->where('source_id', $exit->id)
                ->where('product_id', $ei->product_id)
                ->first();

            $wipEntry = ProjectWipEntry::where('source_type', 'App\\Models\\StockExit')
                ->where('source_id', $exit->id)
                ->first();

            $this->line("  ─ [{$ei->product?->code}] {$ei->product?->name}");
            $this->table(['Field', 'Value'], [
                ['stock_exit_item_id', $ei->id],
                ['product_id', $ei->product_id],
                ['quantity', $ei->quantity],
                ['warehouse_id', $ei->warehouse_id ?? ($exit->warehouse_id . ' (from exit)')],
                ['order_item_id', $ei->order_item_id ? $ei->order_item_id : 'NULL ⚠ (thiếu link)'],
                ['unit_cost / source_cost', $ei->source_cost ?? $ei->unit_price],
                ['total_cost', $ei->total_cost],
                ['movement_id', $movement?->id ?? 'null'],
                ['wip_entry_id', $wipEntry?->id ?? 'null'],
            ]);
        }

        if (! $exit->order_id) {
            $this->newLine();
            $this->warn("⚠ PHIẾU NÀY THIẾU order_id — syncDelivery() sẽ bị bỏ qua.");
            $this->warn("  Chạy: php artisan stock-exits:repair-sales-order-links --stock-exit={$exit->code} --order=DH-xxxx --dry-run");
        }

        $itemsNoLink = $exit->items->filter(fn ($ei) => ! $ei->order_item_id);
        if ($itemsNoLink->isNotEmpty()) {
            $this->warn("⚠ {$itemsNoLink->count()} item(s) thiếu order_item_id — delivered_quantity sẽ không được cập nhật.");
        }

        $this->newLine();
        return 0;
    }
}
