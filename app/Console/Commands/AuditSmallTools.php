<?php

namespace App\Console\Commands;

use App\Models\SmallTool;
use App\Services\SmallToolAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSmallTools extends Command
{
    protected $signature   = 'ccdc:audit {--fix : Auto-fix missing pending allocations}';
    protected $description = 'Audit CCDC integrity: pending allocations, status consistency, GL snapshot';

    public function handle(SmallToolAllocationService $allocationService): int
    {
        $this->info('=== CCDC Audit ===');

        $issues = collect();

        // 1. Tools in allocating state with no pending allocations
        SmallTool::where('status', 'allocating')
            ->withCount(['allocations as pending_count' => fn($q) => $q->where('status', 'pending')])
            ->get()
            ->each(function ($tool) use (&$issues) {
                if ($tool->pending_count === 0) {
                    $issues->push([
                        'type' => 'MISSING_PENDING',
                        'code' => $tool->code,
                        'name' => $tool->name,
                        'note' => 'Status=allocating but 0 pending allocations',
                    ]);
                }
            });

        // 2. Tools with periods_allocated > allocation_periods
        SmallTool::whereColumn('periods_allocated', '>', 'allocation_periods')
            ->get()
            ->each(fn($t) => $issues->push([
                'type' => 'OVERRUN',
                'code' => $t->code,
                'name' => $t->name,
                'note' => "periods_allocated={$t->periods_allocated} > allocation_periods={$t->allocation_periods}",
            ]));

        // 3. total_allocated mismatch vs SUM of posted allocations
        SmallTool::whereIn('status', ['allocating', 'fully_allocated'])
            ->get()
            ->each(function ($tool) use (&$issues) {
                $sumPosted = $tool->allocations()->where('status', 'posted')->sum('amount');
                if (abs((float) $tool->total_allocated - (float) $sumPosted) > 1) {
                    $issues->push([
                        'type' => 'AMOUNT_MISMATCH',
                        'code' => $tool->code,
                        'name' => $tool->name,
                        'note' => "total_allocated={$tool->total_allocated} vs SUM(posted)={$sumPosted}",
                    ]);
                }
            });

        // 4. Draft tools older than 30 days
        SmallTool::where('status', 'draft')
            ->where('created_at', '<', now()->subDays(30))
            ->get()
            ->each(fn($t) => $issues->push([
                'type' => 'STALE_DRAFT',
                'code' => $t->code,
                'name' => $t->name,
                'note' => "Draft since {$t->created_at->toDateString()}",
            ]));

        if ($issues->isEmpty()) {
            $this->info('No issues found. All CCDC records are consistent.');
            return 0;
        }

        $this->table(['Type', 'Code', 'Name', 'Note'], $issues->toArray());
        $this->error("{$issues->count()} issue(s) found.");
        return 1;
    }
}
