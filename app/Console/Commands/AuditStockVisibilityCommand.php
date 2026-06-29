<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStockVisibilityCommand extends Command
{
    protected $signature = 'inventory:audit-stock-visibility
                            {--product= : Mã sản phẩm (code) cần kiểm tra}
                            {--warehouse= : Lọc theo warehouse_id}';

    protected $description = 'Kiểm tra tồn kho theo từng kho, đối chiếu UI stock vs backend AVCO, serial khả dụng.';

    public function handle(): int
    {
        $productCode = $this->option('product');
        $warehouseId = $this->option('warehouse');

        $productQuery = Product::query();
        if ($productCode) {
            $productQuery->where('code', $productCode);
        }
        $products = $productQuery->where('is_active', true)->get(['id', 'code', 'name', 'unit']);

        if ($products->isEmpty()) {
            $this->error("Không tìm thấy sản phẩm" . ($productCode ? " với mã {$productCode}" : '') . ".");
            return 1;
        }

        $warehouses = Warehouse::where('is_active', true)
            ->when($warehouseId, fn ($q) => $q->where('id', $warehouseId))
            ->get(['id', 'name']);

        foreach ($products as $product) {
            $this->newLine();
            $this->line("─────────────────────────────────────────────────");
            $this->info("Sản phẩm: {$product->code} — {$product->name}");
            $this->line("─────────────────────────────────────────────────");

            // 1. inventory_balances theo từng kho
            $balances = InventoryBalance::where('product_id', $product->id)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->with('warehouse')
                ->get();

            $totalAvco = 0.0;
            if ($balances->isNotEmpty()) {
                $this->line("  [AVCO inventory_balances]");
                foreach ($balances as $b) {
                    $flag = (float) $b->qty_on_hand <= 0 ? ' ⚠ qty=0' : '';
                    $this->line("    {$b->warehouse->name}: qty_on_hand={$b->qty_on_hand}, avg_cost={$b->avg_cost}{$flag}");
                    $totalAvco += (float) $b->qty_on_hand;
                }
                $this->line("    → Tổng AVCO: {$totalAvco} {$product->unit}");
            } else {
                $this->warn("  [AVCO] Chưa có inventory_balances cho sản phẩm này" . ($warehouseId ? " tại kho #{$warehouseId}" : '') . ".");
            }

            // 2. SUM active stock_movements theo từng kho
            $movRows = DB::table('stock_movements')
                ->where('product_id', $product->id)
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->select('warehouse_id', DB::raw('SUM(quantity) as qty'))
                ->groupBy('warehouse_id')
                ->get()
                ->keyBy('warehouse_id');

            $this->line("  [Active stock_movements]");
            $totalMov = 0.0;
            foreach ($warehouses as $wh) {
                $movQty = (float) ($movRows[$wh->id]->qty ?? 0);
                $totalMov += $movQty;
                $avcoQty  = (float) ($balances->firstWhere('warehouse_id', $wh->id)?->qty_on_hand ?? 'N/A');
                $mismatch = ($movRows->has($wh->id) && is_float($avcoQty) && abs($movQty - $avcoQty) > 0.001)
                    ? " ⚠ LỆCH AVCO={$avcoQty}" : '';
                if ($movRows->has($wh->id) || $balances->contains('warehouse_id', $wh->id)) {
                    $this->line("    {$wh->name}: movements={$movQty}{$mismatch}");
                }
            }
            $this->line("    → Tổng active movements: {$totalMov} {$product->unit}");

            // 3. Voided movements (thông tin bổ sung)
            $voidedRows = DB::table('stock_movements')
                ->where('product_id', $product->id)
                ->where('status', 'voided')
                ->select('warehouse_id', DB::raw('SUM(quantity) as qty'))
                ->groupBy('warehouse_id')
                ->get();

            if ($voidedRows->isNotEmpty()) {
                $this->line("  [Voided stock_movements - không tính tồn]");
                foreach ($voidedRows as $row) {
                    $wh = $warehouses->firstWhere('id', $row->warehouse_id);
                    $whName = $wh?->name ?? "warehouse_id={$row->warehouse_id}";
                    $this->line("    {$whName}: voided_qty={$row->qty}");
                }
            }

            // 4. Serial khả dụng theo kho
            $serials = ProductSerial::where('product_id', $product->id)
                ->where('status', 'in_stock')
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->select('warehouse_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('warehouse_id')
                ->get();

            if ($serials->isNotEmpty()) {
                $this->line("  [Serial khả dụng (status=in_stock)]");
                foreach ($serials as $s) {
                    $wh = $warehouses->firstWhere('id', $s->warehouse_id);
                    $whName = $wh?->name ?? "warehouse_id={$s->warehouse_id}";
                    $avcoQty = (float) ($balances->firstWhere('warehouse_id', $s->warehouse_id)?->qty_on_hand ?? 0);
                    $warn = ($avcoQty > 0 && $s->cnt < $avcoQty) ? " ⚠ serial({$s->cnt}) < avco({$avcoQty})" : '';
                    $this->line("    {$whName}: {$s->cnt} serial{$warn}");
                }
            } else {
                $this->line("  [Serial] Không có serial in_stock cho sản phẩm này.");
            }

            // 5. Cảnh báo lệch AVCO vs movements
            if (abs($totalAvco - $totalMov) > 0.001) {
                $this->warn("  ⚠ TỔNG LỆCH: AVCO={$totalAvco} vs Movements={$totalMov}. Chạy: php artisan inventory:reconcile-balances --all-warehouses --dry-run");
            } else {
                $this->info("  ✓ AVCO khớp movements.");
            }
        }

        $this->newLine();
        return 0;
    }
}
