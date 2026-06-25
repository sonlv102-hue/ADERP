<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\Project;
use App\Models\ProjectInventoryLot;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditProjectIssuesCommand extends Command
{
    protected $signature = 'inventory:audit-project-issues
                            {--project= : Chỉ audit một dự án cụ thể (code DA-xxxx)}';

    protected $description = 'Kiểm tra tính nhất quán của xuất kho dự án (lot FIFO, AVCO fallback, WIP, double-count)';

    public function handle(): int
    {
        $projectFilter = $this->option('project');
        $projects = Project::query()
            ->when($projectFilter, fn ($q) => $q->where('code', $projectFilter))
            ->get();

        if ($projects->isEmpty()) {
            $this->error("Không tìm thấy dự án" . ($projectFilter ? " {$projectFilter}" : '') . '.');
            return self::FAILURE;
        }

        $critical = 0;
        $warnings  = 0;

        foreach ($projects as $project) {
            $this->line("\n=== Dự án: {$project->code} — {$project->name} ===");

            // ── C1: Lot còn available_qty > 0 nhưng toàn bộ movement đã xuất ──────
            $lots = ProjectInventoryLot::where('project_id', $project->id)->get();
            $orphanLots = $lots->filter(function ($lot) {
                $movementIn = StockMovement::where('product_id', $lot->product_id)
                    ->where('warehouse_id', $lot->warehouse_id)
                    ->where('project_id', $lot->project_id)
                    ->where('quantity', '>', 0)
                    ->sum('quantity');

                $lotAvail = (float) $lot->received_qty - (float) $lot->issued_qty;
                return $movementIn <= 0 && $lotAvail > 0;
            });
            if ($orphanLots->isNotEmpty()) {
                $this->warn("  [C1] {$orphanLots->count()} lot có qty còn lại nhưng không có movement nhập vào dự án:");
                foreach ($orphanLots as $lot) {
                    $this->line("       Lot #{$lot->id} product_id={$lot->product_id} avail=" . ($lot->received_qty - $lot->issued_qty));
                }
                $critical++;
            } else {
                $this->info("  [C1] OK: Tất cả lots đều có movement nhập phù hợp.");
            }

            // ── C2: Xuất dự án (project_cost) không có WIP entry ──────────────────
            $projectExits = StockExit::where('project_id', $project->id)
                ->where('issue_purpose', 'project_cost')
                ->where('status', 'confirmed')
                ->pluck('id');

            if ($projectExits->isNotEmpty()) {
                $missingWip = DB::table('stock_exit_items')
                    ->whereIn('stock_exit_items.stock_exit_id', $projectExits)
                    ->leftJoin('project_wip_entries', function ($j) {
                        $j->on('project_wip_entries.source_id', '=', 'stock_exit_items.stock_exit_id')
                          ->where('project_wip_entries.source_type', '=', StockExit::class)
                          ->where('project_wip_entries.status', 'active');
                    })
                    ->whereNull('project_wip_entries.id')
                    ->count();

                if ($missingWip > 0) {
                    $this->warn("  [C2] {$missingWip} dòng xuất dự án thiếu WIP entry.");
                    $critical++;
                } else {
                    $this->info("  [C2] OK: Tất cả xuất dự án đều có WIP entry.");
                }
            } else {
                $this->info("  [C2] Không có phiếu xuất project_cost đã xác nhận.");
            }

            // ── C3: Double-count — cùng qty bị trừ cả lot lẫn AVCO ───────────────
            $doubleCount = DB::table('stock_exit_item_lot_allocations as a')
                ->join('stock_exit_items as i', 'i.id', '=', 'a.stock_exit_item_id')
                ->whereIn('i.stock_exit_id', $projectExits)
                ->whereNull('a.voided_at')
                ->where('i.cost_source', 'avco')
                ->count();

            if ($doubleCount > 0) {
                $this->warn("  [C3] {$doubleCount} dòng xuất có cost_source=avco nhưng vẫn có lot allocation (có thể double-count).");
                $critical++;
            } else {
                $this->info("  [C3] OK: Không phát hiện double-count FIFO+AVCO.");
            }

            // ── W1: Lot FIFO issued_qty > received_qty ────────────────────────────
            $overIssuedLots = $lots->filter(fn ($l) => (float) $l->issued_qty > (float) $l->received_qty);
            if ($overIssuedLots->isNotEmpty()) {
                $this->warn("  [W1] {$overIssuedLots->count()} lot bị over-issued (issued_qty > received_qty).");
                $warnings++;
            } else {
                $this->info("  [W1] OK: Không có lot nào over-issued.");
            }

            // ── W2: Phiếu điều chuyển (project_transfer) thiếu to_warehouse_id ───
            $badTransfers = StockExit::where('project_id', $project->id)
                ->where('issue_purpose', 'project_transfer')
                ->whereNull('to_warehouse_id')
                ->count();
            if ($badTransfers > 0) {
                $this->warn("  [W2] {$badTransfers} phiếu project_transfer thiếu to_warehouse_id.");
                $warnings++;
            } else {
                $this->info("  [W2] OK: Tất cả phiếu điều chuyển đều có kho đích.");
            }

            // ── Info: Tổng tồn AVCO cho sản phẩm dự án ───────────────────────────
            $productIds = $lots->pluck('product_id')->unique();
            if ($productIds->isNotEmpty()) {
                $avcoSum = InventoryBalance::whereIn('product_id', $productIds)->sum('qty_on_hand');
                $this->line("  [INFO] Tổng tồn AVCO kho chung cho sản phẩm dự án: {$avcoSum}");
                $lotRemaining = $lots->sum(fn ($l) => (float) $l->received_qty - (float) $l->issued_qty);
                $this->line("  [INFO] Tổng tồn lot FIFO dự án còn lại: {$lotRemaining}");
            }
        }

        $this->newLine();
        if ($critical === 0 && $warnings === 0) {
            $this->info("=== Kết quả: Không phát hiện vấn đề. ===");
            return self::SUCCESS;
        }

        $this->line("=== Kết quả: {$critical} critical, {$warnings} warnings ===");
        return $critical > 0 ? self::FAILURE : self::SUCCESS;
    }
}
