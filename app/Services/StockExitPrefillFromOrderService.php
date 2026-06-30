<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Computes prefill data for creating a stock exit from a sales order at a specific warehouse.
 * Uses the same stock source logic as OrderController@show (AVCO first, movement fallback).
 * This ensures Order Show and the Stock Exit Form always agree on available quantities.
 */
class StockExitPrefillFromOrderService
{
    public function getPrefillData(int $orderId, int $warehouseId, ?int $projectId, ?string $issuePurpose): array
    {
        $order = Order::with(['customer', 'items.product', 'project'])->findOrFail($orderId);

        $orderItemIds = $order->items->pluck('id');

        // Confirmed exit qty per order_item_id (authoritative — same as Order Show)
        $confirmedQtyMap = $orderItemIds->isNotEmpty()
            ? StockExitItem::select('stock_exit_items.order_item_id', DB::raw('SUM(stock_exit_items.quantity) as qty'))
                ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
                ->whereIn('stock_exit_items.order_item_id', $orderItemIds)
                ->where('stock_exits.status', 'confirmed')
                ->groupBy('stock_exit_items.order_item_id')
                ->pluck('qty', 'order_item_id')
                ->map(fn ($v) => (float) $v)
            : collect();

        $productIds = $order->items->whereNotNull('product_id')->pluck('product_id');

        // AVCO balances at this warehouse
        $avcoBalances = InventoryBalance::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'qty_on_hand', 'avg_cost'])
            ->keyBy('product_id');

        // Movement fallback: products without positive AVCO balance
        $productsWithAvco = $avcoBalances->filter(fn ($b) => (float) $b->qty_on_hand > 0)->keys()->toArray();
        $productsNeedFallback = $productIds->diff($productsWithAvco)->values();

        $movementQtyMap = collect();
        if ($productsNeedFallback->isNotEmpty()) {
            $movementQtyMap = StockMovement::active()
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productsNeedFallback)
                ->selectRaw('product_id, SUM(quantity) as qty')
                ->groupBy('product_id')
                ->havingRaw('SUM(quantity) > 0')
                ->pluck('qty', 'product_id')
                ->map(fn ($v) => (float) $v);
        }

        $resolvedProjectId = $projectId ?? $order->project_id;
        $resolvedPurpose   = $issuePurpose ?? ($resolvedProjectId ? 'project_cost' : 'sale_delivery');

        $items = [];
        foreach ($order->items->whereNotNull('product_id') as $orderItem) {
            $confirmedQty = (float) ($confirmedQtyMap[$orderItem->id] ?? $orderItem->delivered_quantity ?? 0);
            $remaining    = max(0.0, (float) $orderItem->quantity - $confirmedQty);

            if ($remaining <= 0) {
                continue;
            }

            $avco = $avcoBalances->get($orderItem->product_id);
            if ($avco && (float) $avco->qty_on_hand > 0) {
                $availableQty = (float) $avco->qty_on_hand;
                $unitCost     = (float) $avco->avg_cost;
                $stockSource  = 'avco';
            } elseif ($movementQtyMap->has($orderItem->product_id)) {
                $availableQty = $movementQtyMap->get($orderItem->product_id);
                $unitCost     = 0.0; // Cannot determine from movements — user must fill
                $stockSource  = 'movement_fallback';
            } else {
                continue; // no stock at this warehouse → skip
            }

            $suggestedQty = min($remaining, $availableQty);
            if ($suggestedQty <= 0) {
                continue;
            }

            $items[] = [
                'sales_order_item_id'                => $orderItem->id,
                'product_id'                         => $orderItem->product_id,
                'product_code'                       => $orderItem->product?->code ?? '',
                'product_name'                       => $orderItem->name,
                'unit'                               => $orderItem->unit ?? '',
                'has_serial'                         => (bool) ($orderItem->product?->has_serial ?? false),
                'ordered_qty'                        => (float) $orderItem->quantity,
                'confirmed_exit_qty'                 => $confirmedQty,
                'remaining_qty'                      => $remaining,
                'available_qty_at_selected_warehouse' => $availableQty,
                'suggested_exit_qty'                 => $suggestedQty,
                'unit_cost'                          => $unitCost,
                'total_cost'                         => round($suggestedQty * $unitCost, 2),
                'stock_source'                       => $stockSource,
            ];
        }

        return [
            'order_id'      => $order->id,
            'order_code'    => $order->code,
            'customer_id'   => $order->customer_id,
            'customer_name' => $order->customer->name,
            'customer_code' => $order->customer->code ?? '',
            'project_id'    => $resolvedProjectId,
            'project_code'  => $order->project?->code,
            'project_name'  => $order->project?->name,
            'warehouse_id'  => $warehouseId,
            'issue_purpose' => $resolvedPurpose,
            'items'         => $items,
        ];
    }
}
