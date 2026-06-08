<?php

namespace App\Console\Commands;

use App\Models\InventoryOpeningBalance;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOpeningStockMovements extends Command
{
    protected $signature = 'inventory:backfill-opening-movements {--dry-run : Xem trước không ghi DB}';
    protected $description = 'Tạo stock_movements cho các bản ghi inventory_opening_balances chưa có movement';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $balances = InventoryOpeningBalance::whereNotIn(
            'id',
            DB::table('stock_movements')
                ->where('source_type', InventoryOpeningBalance::class)
                ->whereNotNull('source_id')
                ->select('source_id')
        )->where('quantity', '>', 0)->get();

        if ($balances->isEmpty()) {
            $this->info('Không có bản ghi nào cần backfill.');
            return 0;
        }

        $this->info("Tìm thấy {$balances->count()} bản ghi cần tạo movement.");

        if ($dryRun) {
            $this->table(
                ['period', 'warehouse_id', 'product_id', 'quantity'],
                $balances->map(fn ($b) => [$b->period, $b->warehouse_id, $b->product_id, $b->quantity])->toArray()
            );
            $this->warn('Dry-run: không ghi DB. Chạy lại không có --dry-run để thực hiện.');
            return 0;
        }

        $count = 0;
        foreach ($balances as $balance) {
            $movDate = Carbon::createFromFormat('Y-m', $balance->period)->startOfMonth()->subSecond();
            DB::table('stock_movements')->insert([
                'warehouse_id' => $balance->warehouse_id,
                'product_id'   => $balance->product_id,
                'quantity'     => (float) $balance->quantity,
                'type'         => 'opening',
                'source_type'  => InventoryOpeningBalance::class,
                'source_id'    => $balance->id,
                'created_by'   => $balance->created_by ?? 1,
                'notes'        => "Tồn kho đầu kỳ {$balance->period}",
                'created_at'   => $movDate,
                'updated_at'   => now(),
            ]);
            $count++;
        }

        $this->info("Đã tạo {$count} stock_movements.");
        return 0;
    }
}
