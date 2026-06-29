<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\StockExit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairSalesOrderLinksCommand extends Command
{
    protected $signature = 'stock-exits:repair-sales-order-links
                            {--stock-exit= : Mã phiếu xuất (XK-) — bỏ qua nếu dùng --order một mình}
                            {--order= : Mã đơn hàng (DH-)}
                            {--dry-run : Chỉ xem, không lưu}
                            {--apply : Áp dụng thay đổi}';

    protected $description = 'Sửa liên kết order_id + order_item_id cho phiếu xuất thiếu link đơn hàng.';

    private bool $dryRun = true;

    public function handle(): int
    {
        $exitCode  = $this->option('stock-exit');
        $orderCode = $this->option('order');
        $this->dryRun = ! $this->option('apply');

        if (! $orderCode) {
            $this->error('Cần --order=DH-xxx (và tuỳ chọn --stock-exit=XK-xxx)');
            return 1;
        }

        $order = Order::with('items')->where('code', $orderCode)->first();
        if (! $order) {
            $this->error("Không tìm thấy đơn hàng {$orderCode}.");
            return 1;
        }

        if ($this->dryRun) {
            $this->info('[DRY RUN] Không có thay đổi nào được lưu. Thêm --apply để áp dụng.');
        }

        // Nếu có --stock-exit thì chỉ xử lý exit đó; không có thì auto-find
        $exits = $exitCode
            ? collect([StockExit::with(['items.product'])->where('code', $exitCode)->firstOrFail()])
            : $this->findCandidateExits($order);

        if ($exits->isEmpty()) {
            $this->warn("Không tìm thấy phiếu xuất nào thiếu order_id phù hợp với {$orderCode}.");
            return 0;
        }

        foreach ($exits as $exit) {
            $this->repairExit($exit, $order);
        }

        $this->newLine();
        return 0;
    }

    private function findCandidateExits(Order $order): \Illuminate\Support\Collection
    {
        $productIds = $order->items->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isEmpty()) return collect();

        // Tìm exits chưa có order_id, có cùng customer_id, chứa ít nhất 1 sản phẩm trong đơn
        $candidates = StockExit::with(['items.product'])
            ->whereNull('order_id')
            ->where('customer_id', $order->customer_id)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->filter(function (StockExit $exit) use ($productIds) {
                $exitProductIds = $exit->items->pluck('product_id')->filter()->unique();
                return $exitProductIds->intersect($productIds)->isNotEmpty();
            });

        if ($candidates->isNotEmpty()) {
            $this->info("Auto-tìm thấy " . $candidates->count() . " phiếu xuất thiếu order_id phù hợp:");
            foreach ($candidates as $e) {
                $this->line("  {$e->code} (status=" . ($e->status instanceof \BackedEnum ? $e->status->value : $e->status) . ", customer_id={$e->customer_id})");
            }
            $this->newLine();
        }

        return $candidates;
    }

    private function repairExit(StockExit $exit, Order $order): void
    {
        $this->info("─── Phiếu: {$exit->code} (order_id hiện tại: " . ($exit->order_id ?? 'NULL') . ")");

        $orderItemsByProduct = $order->items
            ->whereNotNull('product_id')
            ->groupBy('product_id');

        $needsManual = [];
        $toRepairItems = [];

        foreach ($exit->items as $ei) {
            if (! $ei->product_id) continue;

            $matches = $orderItemsByProduct->get($ei->product_id, collect());

            if ($matches->isEmpty()) {
                $this->warn("  ✗ [{$ei->product?->code}] không có trong đơn {$order->code} — bỏ qua.");
                continue;
            }

            if ($matches->count() > 1) {
                $ids = $matches->pluck('id')->join(', ');
                $this->warn("  ⚠ [{$ei->product?->code}] xuất hiện {$matches->count()} dòng (ids: {$ids}) — cần gán thủ công.");
                $needsManual[] = $ei->product?->code;
                continue;
            }

            $orderItem = $matches->first();
            $this->line("  ✓ [{$ei->product?->code}] exit_item #{$ei->id} → order_item #{$orderItem->id}");
            $toRepairItems[] = ['exit_item_id' => $ei->id, 'order_item_id' => $orderItem->id];
        }

        if (! $this->dryRun && (! $exit->order_id || $toRepairItems)) {
            DB::transaction(function () use ($exit, $order, $toRepairItems) {
                if (! $exit->order_id) {
                    $exit->update(['order_id' => $order->id]);
                    $this->line("  → stock_exits.order_id = {$order->id}");
                }
                foreach ($toRepairItems as $repair) {
                    DB::table('stock_exit_items')
                        ->where('id', $repair['exit_item_id'])
                        ->update(['order_item_id' => $repair['order_item_id']]);
                }
            });

            $this->info('Chạy recalculate-delivered-qty...');
            $this->call('sales-orders:recalculate-delivered-qty', [
                '--order' => $order->code,
                '--apply' => true,
            ]);
        } elseif ($this->dryRun) {
            $this->info('[DRY RUN] Sẽ sửa:');
            if (! $exit->order_id) {
                $this->line("  stock_exits.order_id = {$order->id}");
            }
            foreach ($toRepairItems as $r) {
                $this->line("  stock_exit_items #{$r['exit_item_id']}.order_item_id = {$r['order_item_id']}");
            }
        }

        if ($needsManual) {
            $this->warn('Cần gán thủ công: ' . implode(', ', $needsManual));
        }
    }
}
