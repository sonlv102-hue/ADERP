<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\InventoryBalance;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugUnfulfilledOrdersCommand extends Command
{
    protected $signature = 'dashboard:debug-unfulfilled-orders
                            {--days=90 : Số ngày nhìn lại (0 = không giới hạn)}
                            {--status=processing : Status đơn hàng (hoặc all)}
                            {--show-all : Hiển thị cả dòng đã giao đủ}';

    protected $description = 'Debug danh sách đơn hàng chưa giao đủ — so sánh tồn kho cũ vs inventory_balances.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $status = $this->option('status');

        $this->info('=== Debug Unfulfilled Orders ===');
        if ($days > 0) {
            $this->line("Filter: {$days} ngày gần nhất");
        } else {
            $this->line('Filter: Không giới hạn ngày');
        }

        $query = Order::with(['customer', 'items'])
            ->where('status', '!=', OrderStatus::Cancelled->value);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($days > 0) {
            $since = now()->subDays($days)->startOfDay();
            $query->where('order_date', '>=', $since);
        }

        $orders = $query->latest('order_date')->get();

        if ($orders->isEmpty()) {
            $this->info('Không tìm thấy đơn hàng nào.');
            return self::SUCCESS;
        }

        $productIds = $orders->flatMap->items->whereNotNull('product_id')->pluck('product_id')->unique();

        // Tồn kho theo 2 logic
        $stocksOld = DB::table('stock_movements')
            ->whereIn('product_id', $productIds)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stocksNew = InventoryBalance::stockForProducts($productIds); // AVCO + fallback movements

        $rows         = [];
        $dashboardIncluded = 0;
        $since90      = now()->subDays(90)->startOfDay();

        foreach ($orders as $order) {
            $inDashboard = $order->order_date >= $since90;

            foreach ($order->items as $item) {
                if (! $item->product_id) continue;

                $remaining = max(0, (float) $item->quantity - (float) $item->delivered_quantity);
                if (! $this->option('show-all') && $remaining <= 0) continue;

                $stockOld = (float) ($stocksOld[$item->product_id] ?? 0);
                $stockNew = (float) ($stocksNew[$item->product_id] ?? 0);
                $shortOld = max(0, $remaining - $stockOld);
                $shortNew = max(0, $remaining - $stockNew);

                if ($inDashboard && $remaining > 0) $dashboardIncluded++;

                $rows[] = [
                    $order->code,
                    $order->order_date->format('d/m/Y'),
                    $order->status->value,
                    mb_substr($item->name, 0, 25),
                    number_format($item->quantity, 0),
                    number_format($item->delivered_quantity, 0),
                    number_format($remaining, 0),
                    number_format($stockOld, 0),
                    number_format($stockNew, 0),
                    $shortOld > 0 ? "Thiếu {$shortOld}" : 'Đủ',
                    $shortNew > 0 ? "Thiếu {$shortNew}" : 'Đủ',
                    $inDashboard ? 'Có (90d)' : 'Không',
                ];
            }
        }

        if (empty($rows)) {
            $this->info('Không có dòng chưa giao nào.');
            return self::SUCCESS;
        }

        $this->table(
            ['Đơn', 'Ngày', 'Status', 'Sản phẩm', 'SL', 'Giao', 'Còn', 'Tồn(movement)', 'Tồn(AVCO+fb)', 'Đánh giá cũ', 'Đánh giá mới', 'Dashboard'],
            $rows
        );

        $this->newLine();
        $this->line("Tổng dòng chưa giao: " . count($rows));
        $this->line("Đơn hàng trong 90 ngày (dashboard): {$orders->filter(fn($o) => $o->order_date >= $since90)->count()} đơn, {$dashboardIncluded} dòng chưa giao");
        $this->line("(Dashboard giới hạn hiển thị 10 đơn đầu)");

        return self::SUCCESS;
    }
}
