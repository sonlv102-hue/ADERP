<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\StockExit;
use App\Models\StockExitItem;
use Illuminate\Console\Command;

class AuditDeliveryOrderCommand extends Command
{
    protected $signature = 'sales-orders:audit-delivery
                            {--order= : Mã đơn hàng (code) cần kiểm tra}';

    protected $description = 'Kiểm tra tình trạng giao hàng của đơn bán: delivered_qty, stock_exit links, tồn kho.';

    public function handle(): int
    {
        $code = $this->option('order');

        $query = Order::with(['items.product', 'customer', 'purchaseOrders.stockEntries'])->orderByDesc('id');
        if ($code) {
            $query->where('code', $code);
        }
        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->error("Không tìm thấy đơn hàng" . ($code ? " với mã {$code}" : '') . ".");
            return 1;
        }

        foreach ($orders as $order) {
            $this->newLine();
            $this->line("══════════════════════════════════════════════════════");
            $this->info("Đơn hàng: {$order->code} | Khách: {$order->customer?->name}");
            $this->line("  Status: {$order->status->value} | customer_id: {$order->customer_id} | project_id: " . ($order->project_id ?? 'null'));
            $this->line("══════════════════════════════════════════════════════");

            // Stock exits liên kết với đơn
            $exits = StockExit::where('order_id', $order->id)
                ->with(['items.product'])
                ->get();

            $confirmedExits = $exits->whereIn('status', ['confirmed', 'posted', 'delivered']);
            $draftExits     = $exits->where('status', 'draft');

            $this->line("  Phiếu xuất liên kết: {$exits->count()} (confirmed: {$confirmedExits->count()}, draft: {$draftExits->count()})");

            foreach ($order->items->whereNotNull('product_id') as $item) {
                // Delivered qty từ stock_exits hợp lệ
                $exitQtyById = 0;
                $exitQtyByProduct = 0;
                $draftQty = 0;
                $missingLink = [];

                foreach ($confirmedExits as $exit) {
                    foreach ($exit->items as $ei) {
                        if ($ei->product_id !== $item->product_id) continue;
                        $exitQtyByProduct += (float) $ei->quantity;
                        if ($ei->order_item_id === $item->id) {
                            $exitQtyById += (float) $ei->quantity;
                        } elseif (! $ei->order_item_id) {
                            $missingLink[] = "XK-{$exit->code}: order_item_id=null";
                        }
                    }
                }
                foreach ($draftExits as $exit) {
                    foreach ($exit->items as $ei) {
                        if ($ei->product_id === $item->product_id) {
                            $draftQty += (float) $ei->quantity;
                        }
                    }
                }

                $remaining = max(0, (float) $item->quantity - (float) $item->delivered_quantity);
                $remainingReal = max(0, (float) $item->quantity - $exitQtyByProduct);

                // Tồn kho
                $stockByWh = InventoryBalance::where('product_id', $item->product_id)
                    ->with('warehouse:id,name')
                    ->get(['product_id', 'warehouse_id', 'qty_on_hand'])
                    ->filter(fn ($b) => (float) $b->qty_on_hand > 0);
                $totalStock = $stockByWh->sum('qty_on_hand');

                // Suggested action đúng
                $hasSufficient = $stockByWh->some(fn ($b) => (float) $b->qty_on_hand >= $remainingReal);
                $suggestedAction = match (true) {
                    $remainingReal <= 0                        => 'Đã giao đủ',
                    $hasSufficient                             => 'Xuất kho',
                    $totalStock > 0                            => 'Xuất từ kho khác / Chuyển kho',
                    default                                    => 'Mua hàng',
                };

                // Current wrong action (based on delivered_quantity field)
                $currentStock = (float) ($stockByWh->sum('qty_on_hand'));
                $currentSuggestion = $remaining <= 0 ? 'Đã giao đủ' : ($currentStock >= $remaining ? 'Xuất kho' : ($currentStock > 0 ? 'Mua hàng (sai - còn tồn)' : 'Mua hàng'));

                $deviates = abs((float) $item->delivered_quantity - $exitQtyByProduct) > 0.001;
                $flag = $deviates ? ' ⚠ LỆCH' : ' ✓';

                $this->line("  ─ [{$item->product?->code}] {$item->name}");
                $this->line("      ordered={$item->quantity} | delivered_qty_field={$item->delivered_quantity}{$flag} | exit_qty_by_product={$exitQtyByProduct} | exit_qty_by_id={$exitQtyById}");
                $this->line("      draft_exit_qty={$draftQty} | remaining_from_field={$remaining} | remaining_from_exits={$remainingReal}");
                $stocked = $stockByWh->map(fn ($b) => "{$b->warehouse?->name}={$b->qty_on_hand}")->join(', ');
                $this->line("      tồn_hệ_thống={$totalStock} [{$stocked}]");
                $this->line("      gợi_ý_hiện_tại={$currentSuggestion} | gợi_ý_đúng={$suggestedAction}");

                // PO/stock entry links
                $hasPo = $order->purchaseOrders->isNotEmpty();
                $hasEntries = $order->purchaseOrders->flatMap(fn ($po) => $po->stockEntries ?? collect())->isNotEmpty();
                $hasExit = $exits->isNotEmpty();
                $this->line("      has_po={$hasPo} | has_stock_receipt={$hasEntries} | has_stock_exit={$hasExit}");

                if ($missingLink) {
                    $this->warn("      missing_link: " . implode(', ', $missingLink));
                }
            }
        }
        $this->newLine();
        return 0;
    }
}
