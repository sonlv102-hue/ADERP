<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Models\StockEntry;
use App\Models\StockExit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInventoryProjectId extends Command
{
    protected $signature = 'inventory:backfill-project-id
                            {--dry-run : Chỉ báo cáo, không sửa dữ liệu}
                            {--entry= : Chỉ xử lý phiếu nhập cụ thể (mã NK-xxxx)}
                            {--scope=all : all|entries|exits}';

    protected $description = 'Backfill project_id còn thiếu trên stock_movements từ source PO/exit';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $scope  = $this->option('scope');
        $entryCode = $this->option('entry');

        $this->info($dryRun ? '[DRY RUN] Không thực hiện cập nhật thực.' : 'Bắt đầu backfill project_id...');

        $totalFixed = 0;

        if (in_array($scope, ['all', 'entries'])) {
            $totalFixed += $this->backfillEntries($dryRun, $entryCode);
        }

        if (in_array($scope, ['all', 'exits'])) {
            $totalFixed += $this->backfillExits($dryRun);
        }

        $this->info($dryRun
            ? "Tổng cần cập nhật: {$totalFixed} movement(s)."
            : "Hoàn thành. Đã cập nhật: {$totalFixed} movement(s)."
        );

        return self::SUCCESS;
    }

    private function backfillEntries(bool $dryRun, ?string $entryCode): int
    {
        $this->line('');
        $this->line('[Entries] Tìm movement nhập kho thiếu project_id...');

        // Tìm movements từ StockEntry mà thiếu project_id, nhưng entry lại có project qua PO
        $query = DB::table('stock_movements as m')
            ->join('stock_entries as e', 'e.id', '=', 'm.source_id')
            ->join('purchase_orders as po', 'po.id', '=', 'e.purchase_order_id')
            ->whereNull('m.project_id')
            ->whereNotNull('po.project_id')
            ->where('m.source_type', StockEntry::class);

        if ($entryCode) {
            $query->where('e.code', $entryCode);
        }

        $rows = $query->select('m.id as movement_id', 'po.project_id', 'e.code as entry_code')->get();

        $count = $rows->count();
        $this->line("  Tìm thấy {$count} movement(s) từ entries thiếu project_id.");

        if ($count === 0 || $dryRun) {
            if ($dryRun && $count > 0) {
                foreach ($rows->take(10) as $row) {
                    $this->line("  - movement #{$row->movement_id} [{$row->entry_code}] → project_id={$row->project_id}");
                }
                if ($count > 10) {
                    $this->line("  ... và " . ($count - 10) . " movement(s) khác");
                }
            }
            return $count;
        }

        $fixed = 0;
        foreach ($rows as $row) {
            DB::table('stock_movements')->where('id', $row->movement_id)
                ->update(['project_id' => $row->project_id]);
            $fixed++;
        }

        $this->info("  Đã cập nhật {$fixed} movement(s) từ entries.");
        return $fixed;
    }

    private function backfillExits(bool $dryRun): int
    {
        $this->line('');
        $this->line('[Exits] Tìm movement xuất kho thiếu project_id...');

        $rows = DB::table('stock_movements as m')
            ->join('stock_exits as x', 'x.id', '=', 'm.source_id')
            ->whereNull('m.project_id')
            ->whereNotNull('x.project_id')
            ->where('m.source_type', StockExit::class)
            ->select('m.id as movement_id', 'x.project_id', 'x.code as exit_code')
            ->get();

        $count = $rows->count();
        $this->line("  Tìm thấy {$count} movement(s) từ exits thiếu project_id.");

        if ($count === 0 || $dryRun) {
            if ($dryRun && $count > 0) {
                foreach ($rows->take(10) as $row) {
                    $this->line("  - movement #{$row->movement_id} [{$row->exit_code}] → project_id={$row->project_id}");
                }
                if ($count > 10) {
                    $this->line("  ... và " . ($count - 10) . " movement(s) khác");
                }
            }
            return $count;
        }

        $fixed = 0;
        foreach ($rows as $row) {
            DB::table('stock_movements')->where('id', $row->movement_id)
                ->update(['project_id' => $row->project_id]);
            $fixed++;
        }

        $this->info("  Đã cập nhật {$fixed} movement(s) từ exits.");
        return $fixed;
    }
}
