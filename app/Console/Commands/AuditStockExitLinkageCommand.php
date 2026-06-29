<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use App\Models\ProjectWipEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStockExitLinkageCommand extends Command
{
    protected $signature = 'stock-exits:audit-linkage
                            {--code= : Mã phiếu xuất (XK-)}
                            {--order= : Mã đơn hàng (DH-) — liệt kê tất cả exits liên quan}';

    protected $description = 'Kiểm tra đầy đủ liên kết của phiếu xuất kho.';

    public function handle(): int
    {
        $code  = $this->option('code');
        $orderCode = $this->option('order');

        if ($orderCode) {
            return $this->auditByOrder($orderCode);
        }

        if (! $code) {
            $this->error('Vui lòng cung cấp --code=XK-xxx hoặc --order=DH-xxx');
            return 1;
        }

        return $this->auditExit($code);
    }

    private function auditByOrder(string $orderCode): int
    {
        $order = Order::with('items.product')->where('code', $orderCode)->first();
        if (! $order) {
            $this->error("Không tìm thấy đơn hàng {$orderCode}.");
            return 1;
        }

        $this->newLine();
        $this->info("AUDIT ĐƠN HÀNG: {$order->code} (ID {$order->id})");
        $this->info("Customer ID: {$order->customer_id} | Status: " . ($order->status instanceof \BackedEnum ? $order->status->value : $order->status));
        $this->newLine();

        $orderItemIds = $order->items->pluck('id');

        // Exits qua order_id
        $exitsByOrderId = StockExit::where('order_id', $order->id)->pluck('id');

        // Exits qua order_item_id
        $exitIdsViaItems = StockExitItem::whereIn('order_item_id', $orderItemIds)
            ->whereNotIn('stock_exit_id', $exitsByOrderId)
            ->distinct()->pluck('stock_exit_id');

        $allExitIds = $exitsByOrderId->merge($exitIdsViaItems)->unique();

        if ($allExitIds->isEmpty()) {
            $this->warn("Không tìm thấy phiếu xuất nào liên quan đến {$orderCode}.");
            $this->warn("Gợi ý: kiểm tra customer_id={$order->customer_id} trên stock_exits.");
            return 0;
        }

        $this->info("Tìm thấy " . $allExitIds->count() . " phiếu xuất:");
        $exits = StockExit::with(['items', 'order'])->whereIn('id', $allExitIds)->orderBy('id')->get();
        foreach ($exits as $exit) {
            $linkedVia = $exitsByOrderId->contains($exit->id) ? 'order_id' : 'order_item_id';
            $missingOrderId = ! $exit->order_id ? ' ⚠ THIẾU order_id' : '';
            $itemsMissingLink = $exit->items->filter(fn ($ei) => ! $ei->order_item_id)->count();
            $this->line("  {$exit->code} (ID {$exit->id}) via={$linkedVia}{$missingOrderId} | status=" . ($exit->status instanceof \BackedEnum ? $exit->status->value : $exit->status) . " | items_missing_link={$itemsMissingLink}");
        }

        // Live confirmed qty per order item
        $confirmedQty = StockExitItem::select('order_item_id', DB::raw('SUM(quantity) as qty'))
            ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
            ->whereIn('order_item_id', $orderItemIds)
            ->where('stock_exits.status', 'confirmed')
            ->groupBy('order_item_id')
            ->pluck('qty', 'order_item_id')
            ->map(fn ($v) => (float) $v);

        $this->newLine();
        $this->info("TRẠNG THÁI GIAO HÀNG TỪNG DÒNG:");
        $rows = [];
        foreach ($order->items as $item) {
            $confirmed = $confirmedQty[$item->id] ?? 0.0;
            $remaining = max(0, (float)$item->quantity - $confirmed);
            $rows[] = [
                $item->product?->code ?? '-',
                $item->name,
                $item->quantity,
                $confirmed,
                $item->delivered_quantity,
                $remaining,
                $remaining > 0 ? 'Còn lại' : 'Đã giao đủ',
            ];
        }
        $this->table(['SP Code', 'Tên SP', 'Đặt', 'XN Confirmed (live)', 'delivered_qty (field)', 'Còn lại', 'Status'], $rows);

        return 0;
    }

    private function auditExit(string $code): int
    {
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
            ['status', $exit->status instanceof \BackedEnum ? $exit->status->value : ($exit->status ?? 'null')],
            ['issue_purpose', $exit->issue_purpose instanceof \BackedEnum ? $exit->issue_purpose->value : ($exit->issue_purpose ?? 'null')],
            ['warehouse_id', $exit->warehouse_id ?? 'null'],
            ['customer_id', $exit->customer_id ?? 'null'],
            ['order_id', $exit->order_id ? "{$exit->order_id} ({$exit->order?->code})" : 'NULL ⚠'],
            ['project_id', $exit->project_id ? "{$exit->project_id} ({$exit->project?->code})" : 'null'],
            ['journal_entry_id', $exit->journal_entry_id ? "{$exit->journal_entry_id}" : 'null'],
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
                ['order_item_id', $ei->order_item_id ? $ei->order_item_id : 'NULL ⚠ (thiếu link)'],
                ['unit_cost', $ei->unit_price],
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
            $this->warn("⚠ {$itemsNoLink->count()} item(s) thiếu order_item_id.");
        }

        $this->newLine();
        return 0;
    }
}
