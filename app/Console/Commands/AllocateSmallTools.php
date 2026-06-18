<?php

namespace App\Console\Commands;

use App\Services\SmallToolAllocationService;
use Illuminate\Console\Command;

class AllocateSmallTools extends Command
{
    protected $signature   = 'ccdc:allocate {period? : YYYY-MM, default current month} {--dry-run : Preview only, no DB changes}';
    protected $description = 'Run monthly CCDC allocation for a given period';

    public function handle(SmallToolAllocationService $service): int
    {
        $period = $this->argument('period') ?? now()->format('Y-m');
        $isDry  = (bool) $this->option('dry-run');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Period must be YYYY-MM format, got: $period");
            return 1;
        }

        $this->info("CCDC allocation — period: $period" . ($isDry ? ' [DRY RUN]' : ''));

        $preview = $service->previewPeriod($period);

        if (!$preview) {
            $this->info('No CCDC items pending for this period.');
            return 0;
        }

        $this->table(
            ['Code', 'Name', 'Debit Account', 'Amount'],
            collect($preview)->map(fn($r) => [
                $r['tool_code'],
                $r['tool_name'],
                $r['debit_account'],
                number_format($r['amount']),
            ])
        );

        $total = collect($preview)->sum('amount');
        $this->info(sprintf('Total: %s VND across %d items.', number_format($total), count($preview)));

        if ($isDry) {
            $this->warn('Dry run — no changes made.');
            return 0;
        }

        if (!$this->confirm("Run allocation for $period?")) {
            return 0;
        }

        $result = $service->runPeriod($period, false);
        $this->info("Done. Journal entry ID: {$result['journal_entry_id']}. Posted {$result['count']} allocations.");
        return 0;
    }
}
