<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\StockExit;
use App\Models\StockExitItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditDeliveryOrderCommand extends Command
{
    protected $signature = 'sales-orders:audit-delivery
                            {--order= : Mã đơn hàng (code) cần kiểm tra}';

    protected $description = 'Kiểm tra tình trạng giao hàng của đơn bán: delivered_qty, stock_exit links, tồn kho.';

    public function handle(): int
    {
        $code  = $this->option('order');
        $query = Order::with(['items.product', 'customer'])->orderByDesc('id');
        if ($code) {
            $query->where('code', $code);
        }
        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->error("Không tìm thấy đơn hàng" . ($code ? " {$code}" : '') . ".");
            return 1;
        }

        foreach ($orders as $order) {
            $this->newLine();
            $this->line("══════════════════════════════════════════════════════");
            $this->info("Đơn hàng: {$order->code} | Khách: {$order->customer?->name}");
            $this->line("  Status: {$order->status->value} | project_id: " . ($order->project_id ?? 'null'));
            $this->line("══════════════════════════════════════════════════════");

            $orderItemIds = $order->items->pluck('id');

            // Exits qua order_id (cách truyền thống)
            $exitsByOrderId = StockExit::where('order_id', $order->id)->with(['items.product'])->get();

            // Exits qua order_item_id (chưa set order_id — như XK-0003 sau repair)
            $exitIdsViaItemLink = $orderItemIds->isNotEmpty()
                ? StockExitItem::whereIn('order_item_id', $orderItemIds)
                    ->whereNotIn('stock_exit_id', $exitsByOrderId->pluck('id'))
                    ->distinct()->pluck('stock_exit_id')
                : collect();

            $exitsByItemLink = $exitIdsViaItemLink->isNotEmpty()
                ? StockExit::whereIn('id', $exitIdsViaItemLink)->with(['items.product'])->get()
                : collect();

            $allExits = $exitsByOrderId->merge($exitsByItemLink)->unique('id');
            $confirmedExits = $allExits->where('status', 'confirmed');
            $draftExits     = $allExits->where('status', 'draft');

            $this->line("  Qua order_id: {$exitsByOrderId->count()} | Qua order_item_id: {$exitsByItemLink->count()}");
            $this->line("  Tổng: {$allExits->count()} (confirmed: {$confirmedExits->count()}, draft: {$draftExits->count()})");

            if ($exitsByItemLink->isNotEmpty()) {
                $this->warn("  ⚠ Phiếu xuất link qua order_item_id nhưng order_id=NULL: " . $exitsByItemLink->pluck('code')->join(', '));
            }

            // Live confirmed qty per order_item_id (JOIN)
            $confirmedQtyByItemId = $orderItemIds->isNotEmpty()
                ? StockExitItem::select('stock_exit_items.order_item_id', DB::raw('SUM(stock_exit_items.quantity) as qty'))
                    ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
                    ->whereIn('stock_exit_items.order_item_id', $orderItemIds)
                    ->where('stock_exits.status', 'confirmed')
                    ->groupBy('stock_exit_items.order_item_id')
                    ->pluck('qty', 'order_item_id')
                    ->map(fn ($v) => (float) $v)
                : collect();

            foreach ($order->items->whereNotNull('product_id') as $item) {
                $exitQtyLive     = (float) ($confirmedQtyByItemId[$item->id] ?? 0);
                $exitQtyByProduct = 0;
                $draftQty         = 0;
                $linkedCodes      = [];

                foreach ($confirmedExits as $exit) {
                    foreach ($exit->items as $ei) {
                        if ($ei->product_id !== $item->product_id) continue;
                        $exitQtyByProduct += (float) $ei->quantity;
                        $linkedCodes[]     = $exit->code;
                    }
                }
                foreach ($draftExits as $exit) {
                    foreach ($exit->items as $ei) {
                        if ($ei->product_id !== $item->product_id) continue;
                        $draftQty      += (float) $ei->quantity;
                        $linkedCodes[]  = $exit->code . '(draft)';
                    }
                }

                $linkedCodes   = array_unique($linkedCodes);
                $remaining     = max(0, (float) $item->quantity - (float) $item->delivered_quantity);
                $remainingLive = max(0, (float) $item->quantity - max((float) $item->delivered_quantity, $exitQtyLive));

                $stockByWh  = InventoryBalance::where('product_id', $item->product_id)
                    ->with('warehouse:id,name')
                    ->get(['product_id', 'warehouse_id', 'qty_on_hand'])
                    ->filter(fn ($b) => (float) $b->qty_on_hand > 0);
                $totalStock = (float) $stockByWh->sum('qty_on_hand');

                $suggestedAction = match (true) {
                    $remainingLive <= 0 => 'Đã giao đủ',
                    $stockByWh->some(fn ($b) => (float) $b->qty_on_hand >= $remainingLive) => 'Xuất kho',
                    $totalStock > 0     => 'Xuất từ kho khác / Chuyển kho',
                    default             => 'Mua hàng',
                };

                $reason = '';
                if ($remaining > 0 && $totalStock <= 0 && $exitQtyLive <= 0 && (float)$item->delivered_quantity <= 0) {
                    $reason = "delivered_qty=0 (stale) + live_confirmed=0 (no order_item_id) + stock=0 → Mua hàng SAI";
                } elseif ($remaining > 0 && $exitQtyLive >= (float)$item->quantity) {
                    $reason = "Đã xuất đủ qua live JOIN nhưng delivered_qty chưa sync — cần repair+recalculate";
                }

                $deviates = $exitQtyLive > 0 && abs((float) $item->delivered_quantity - $exitQtyLive) > 0.001;
                $flag     = $deviates ? ' ⚠' : ' ✓';

                $this->line("  ─ [{$item->product?->code}] {$item->name}");
                $this->line("      ordered={$item->quantity} | delivered_qty_field={$item->delivered_quantity}{$flag}");
                $this->line("      confirmed_exit_qty_live={$exitQtyLive} | by_product={$exitQtyByProduct} | draft={$draftQty}");
                $this->line("      remaining_field={$remaining} | remaining_live={$remainingLive} | tồn={$totalStock}");
                $this->line("      gợi_ý_đúng={$suggestedAction} | linked: " . (empty($linkedCodes) ? 'none' : implode(', ', $linkedCodes)));
                if ($reason) {
                    $this->warn("      ⚠ {$reason}");
                }
            }
        }
        $this->newLine();
        return 0;
    }
}
