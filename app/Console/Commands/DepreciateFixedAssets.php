<?php

namespace App\Console\Commands;

use App\Services\FixedAssetService;
use Illuminate\Console\Command;

class DepreciateFixedAssets extends Command
{
    protected $signature = 'assets:depreciate {--period= : Period in YYYY-MM format (defaults to current month)}';
    protected $description = 'Run monthly straight-line depreciation for all active fixed assets';

    public function handle(FixedAssetService $service): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Invalid period format. Use YYYY-MM (e.g. 2026-05).");
            return 1;
        }

        $this->info("Running depreciation for period: {$period}");

        $result = $service->runMonthlyDepreciation($period);

        $this->table(['Metric', 'Count'], [
            ['Processed', $result['processed']],
            ['Skipped',   $result['skipped']],
            ['Errors',    count($result['errors'])],
        ]);

        foreach ($result['errors'] as $err) {
            $this->error($err);
        }

        return count($result['errors']) > 0 ? 1 : 0;
    }
}
