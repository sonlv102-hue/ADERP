<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditReceiptExitDatesCommand extends Command
{
    protected $signature = 'inventory:audit-receipt-exit-dates';

    protected $description = 'Kiểm tra tính đúng đắn của ngày nhập/xuất kho trong stock_movements.';

    private int $issues = 0;

    public function handle(): int
    {
        $this->info('=== Audit ngày nhập/xuất kho ===');

        $this->checkMovementsWithoutDate();
        $this->checkMovementsWithoutUnitCost();
        $this->checkVoidedMovementsCount();
        $this->checkBalanceMismatch();
        $this->checkExitItemsWithoutCost();
        $this->checkMultipleReceiptDates();

        if ($this->issues === 0) {
            $this->info("\n✓ Không phát hiện vấn đề.");
        } else {
            $this->warn("\n⚠ Tổng số vấn đề phát hiện: {$this->issues}");
        }

        return $this->issues > 0 ? 1 : 0;
    }

    private function checkMovementsWithoutDate(): void
    {
        $this->info("\n[1] Movements thiếu ngày chứng từ (chỉ có created_at, không có phiếu NK/XK liên kết):");

        $count = DB::table('stock_movements as sm')
            ->whereRaw("(sm.status IS NULL OR sm.status = 'active')")
            ->whereNotExists(function ($q) {
                $q->from('stock_entries as se')
                  ->whereColumn('se.id', 'sm.source_id')
                  ->where('sm.source_type', 'App\\Models\\StockEntry');
            })
            ->whereNotExists(function ($q) {
                $q->from('stock_exits as sx')
                  ->whereColumn('sx.id', 'sm.source_id')
                  ->where('sm.source_type', 'App\\Models\\StockExit');
            })
            ->whereNotIn('sm.source_type', [
                'App\\Models\\InventoryOpeningBalance',
                'App\\Models\\InventoryCount',
            ])
            ->count();

        if ($count > 0) {
            $this->warn("  ⚠ {$count} movements dùng created_at làm ngày (nguồn không phải NK/XK)");
            $this->issues++;
        } else {
            $this->line("  ✓ Tất cả movements có nguồn NK/XK hoặc nguồn hợp lệ");
        }
    }

    private function checkMovementsWithoutUnitCost(): void
    {
        $this->info("\n[2] Movements xuất kho thiếu unit_cost:");

        $count = DB::table('stock_movements')
            ->whereRaw("(status IS NULL OR status = 'active')")
            ->where('quantity', '<', 0)
            ->where(function ($q) {
                $q->whereNull('unit_cost')->orWhere('unit_cost', '<=', 0);
            })
            ->whereNotIn('source_type', ['App\\Models\\InventoryOpeningBalance'])
            ->count();

        if ($count > 0) {
            $this->warn("  ⚠ {$count} movements xuất thiếu unit_cost — giá trị xuất kho bị sai");
            $this->issues++;

            DB::table('stock_movements as sm')
                ->join('products as p', 'p.id', '=', 'sm.product_id')
                ->whereRaw("(sm.status IS NULL OR sm.status = 'active')")
                ->where('sm.quantity', '<', 0)
                ->where(function ($q) {
                    $q->whereNull('sm.unit_cost')->orWhere('sm.unit_cost', '<=', 0);
                })
                ->select('sm.id', 'p.code as product_code', 'sm.source_type', 'sm.source_id', 'sm.quantity', 'sm.created_at')
                ->limit(10)
                ->get()
                ->each(fn ($r) => $this->line("    SM#{$r->id} {$r->product_code} qty={$r->quantity} src={$r->source_type}#{$r->source_id}"));
        } else {
            $this->line("  ✓ Tất cả movements xuất đều có unit_cost");
        }
    }

    private function checkVoidedMovementsCount(): void
    {
        $this->info("\n[3] Thống kê voided movements:");

        $voided = DB::table('stock_movements')->where('status', 'voided')->count();
        $active = DB::table('stock_movements')
            ->whereRaw("(status IS NULL OR status = 'active')")
            ->count();

        $this->line("  Tổng active: {$active} | Voided: {$voided}");

        if ($voided > 0) {
            $this->line("  ℹ Voided movements bị loại khỏi báo cáo tồn kho — đúng.");
        }
    }

    private function checkBalanceMismatch(): void
    {
        $this->info("\n[4] Kiểm tra inventory_balances không khớp tổng movement active:");

        $mismatches = DB::table('inventory_balances as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->leftJoinSub(
                DB::table('stock_movements')
                    ->selectRaw("product_id, warehouse_id, SUM(quantity) as total_qty")
                    ->whereRaw("(status IS NULL OR status = 'active')")
                    ->groupBy('product_id', 'warehouse_id'),
                'sm_sum',
                function ($join) {
                    $join->on('sm_sum.product_id', '=', 'ib.product_id')
                         ->on('sm_sum.warehouse_id', '=', 'ib.warehouse_id');
                }
            )
            ->whereRaw("ABS(COALESCE(ib.qty_on_hand, 0) - COALESCE(sm_sum.total_qty, 0)) > 0.001")
            ->select('p.code as product_code', 'ib.warehouse_id', 'ib.qty_on_hand', DB::raw('COALESCE(sm_sum.total_qty, 0) as movement_total'))
            ->limit(20)
            ->get();

        if ($mismatches->count() > 0) {
            $this->warn("  ⚠ {$mismatches->count()} sản phẩm có inventory_balance không khớp movement:");
            foreach ($mismatches as $r) {
                $this->line("    {$r->product_code} WH#{$r->warehouse_id}: balance={$r->qty_on_hand} movement={$r->movement_total}");
            }
            $this->issues++;
        } else {
            $this->line("  ✓ inventory_balances khớp tổng movements");
        }
    }

    private function checkExitItemsWithoutCost(): void
    {
        $this->info("\n[5] Stock exit items thiếu unit_cost hoặc total_cost:");

        $count = DB::table('stock_exit_items')
            ->where(function ($q) {
                $q->whereNull('unit_cost')
                  ->orWhereNull('total_cost')
                  ->orWhere('unit_cost', '<=', 0);
            })
            ->count();

        if ($count > 0) {
            $this->warn("  ⚠ {$count} stock_exit_items thiếu unit_cost/total_cost");
            $this->issues++;
        } else {
            $this->line("  ✓ Tất cả stock_exit_items có unit_cost và total_cost");
        }
    }

    private function checkMultipleReceiptDates(): void
    {
        $this->info("\n[6] Sản phẩm nhập nhiều ngày (thông tin, không phải lỗi):");

        $multiDate = DB::table('stock_entries as se')
            ->join('stock_entry_items as sei', 'sei.stock_entry_id', '=', 'se.id')
            ->whereRaw("se.status NOT IN ('cancelled', 'draft')")
            ->selectRaw("sei.product_id, COUNT(DISTINCT se.entry_date) as date_count, MIN(se.entry_date) as first_date, MAX(se.entry_date) as last_date")
            ->groupBy('sei.product_id')
            ->having('date_count', '>', 1)
            ->orderByDesc('date_count')
            ->limit(5)
            ->get();

        if ($multiDate->count() > 0) {
            $this->line("  Top sản phẩm nhập nhiều ngày:");
            foreach ($multiDate as $r) {
                $this->line("    Product#{$r->product_id}: {$r->date_count} ngày nhập ({$r->first_date} → {$r->last_date})");
            }
            $this->line("  ℹ Báo cáo tổng hợp hiển thị 'Ngày nhập gần nhất' — đúng nghiệp vụ.");
            $this->line("  ℹ Xem chi tiết từng lần nhập: Kho > Phiếu nhập kho hoặc Thẻ kho.");
        } else {
            $this->line("  Chưa có sản phẩm nhập nhiều ngày.");
        }
    }
}
