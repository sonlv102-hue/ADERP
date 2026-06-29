<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\StockExit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairSalesOrderLinksCommand extends Command
{
    protected $signature = 'stock-exits:repair-sales-order-links
                            {--stock-exit= : Mã phiếu xuất (XK-)}
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

        if (! $exitCode || ! $orderCode) {
            $this->error('Cần --stock-exit=XK-xxx và --order=DH-xxx');
            return 1;
        }

        if ($this->dryRun) {
            $this->info('[DRY RUN] Không có thay đổi nào được lưu. Thêm --apply để áp dụng.');
        }

        $exit = StockExit::with(['items.product'])->where('code', $exitCode)->first();
        if (! $exit) {
            $this->error("Không tìm thấy phiếu xuất {$exitCode}.");
            return 1;
        }

        $order = Order::with('items')->where('code', $orderCode)->first();
        if (! $order) {
            $this->error("Không tìm thấy đơn hàng {$orderCode}.");
            return 1;
        }

        $this->info("Phiếu: {$exit->code} (order_id hiện tại: " . ($exit->order_id ?? 'NULL') . ")");
        $this->info("Đơn hàng: {$order->code} (id={$order->id})");

        // Nhóm order_items theo product_id
        $orderItemsByProduct = $order->items
            ->whereNotNull('product_id')
            ->groupBy('product_id');

        $needsManual = [];
        $toRepairItems = [];

        foreach ($exit->items as $ei) {
            if (! $ei->product_id) continue;

            $matches = $orderItemsByProduct->get($ei->product_id, collect());

            if ($matches->isEmpty()) {
                $this->warn("  ✗ [{$ei->product?->code}] product_id={$ei->product_id} không có trong đơn {$orderCode} — bỏ qua.");
                continue;
            }

            if ($matches->count() > 1) {
                $ids = $matches->pluck('id')->join(', ');
                $this->warn("  ⚠ [{$ei->product?->code}] xuất hiện {$matches->count()} dòng trong đơn (item ids: {$ids}) — cần gán thủ công.");
                $needsManual[] = $ei->product?->code;
                continue;
            }

            $orderItem = $matches->first();
            $this->line("  ✓ [{$ei->product?->code}] exit_item #{$ei->id} → order_item #{$orderItem->id}");
            $toRepairItems[] = ['exit_item_id' => $ei->id, 'order_item_id' => $orderItem->id];
        }

        if (! $this->dryRun) {
            DB::transaction(function () use ($exit, $order, $toRepairItems) {
                // Gán order_id cho exit nếu chưa có
                if (! $exit->order_id) {
                    $exit->update(['order_id' => $order->id]);
                    $this->line("  → stock_exits.order_id = {$order->id}");
                }

                // Gán order_item_id cho từng item
                foreach ($toRepairItems as $repair) {
                    DB::table('stock_exit_items')
                        ->where('id', $repair['exit_item_id'])
                        ->update(['order_item_id' => $repair['order_item_id']]);
                }
            });

            // Recalculate delivered_quantity
            $this->info('Chạy recalculate-delivered-qty...');
            $this->call('sales-orders:recalculate-delivered-qty', [
                '--order' => $orderCode,
                '--apply' => true,
            ]);
        } else {
            $this->newLine();
            $this->info('[DRY RUN] Sẽ sửa:');
            $this->line("  stock_exits.order_id = {$order->id}");
            foreach ($toRepairItems as $r) {
                $this->line("  stock_exit_items #{$r['exit_item_id']}.order_item_id = {$r['order_item_id']}");
            }
        }

        if ($needsManual) {
            $this->newLine();
            $this->warn('Các sản phẩm cần gán thủ công: ' . implode(', ', $needsManual));
        }

        $this->newLine();
        return 0;
    }
}
