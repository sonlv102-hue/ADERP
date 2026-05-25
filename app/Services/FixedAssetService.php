<?php

namespace App\Services;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    /**
     * Run monthly depreciation for all active assets for the given period (YYYY-MM).
     * Skips assets already depreciated in that period.
     *
     * Returns array ['processed' => int, 'skipped' => int, 'errors' => string[]]
     */
    public function runMonthlyDepreciation(string $period): array
    {
        $processed = 0;
        $skipped   = 0;
        $errors    = [];

        $assets = FixedAsset::whereNull('deleted_at')
            ->where('status', 'active')
            ->where('useful_life_months', '>', 0)
            ->where('acquisition_cost', '>', 0)
            ->get();

        foreach ($assets as $asset) {
            // Skip if already processed for this period
            $exists = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                ->where('period', $period)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            // Skip if acquisition date is after the period end
            $periodEnd = $period . '-' . cal_days_in_month(CAL_GREGORIAN, (int) substr($period, 5, 2), (int) substr($period, 0, 4));
            if ($asset->acquisition_date && $asset->acquisition_date->format('Y-m-d') > $periodEnd) {
                $skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($asset, $period, &$processed) {
                    $monthly = round($asset->acquisition_cost / $asset->useful_life_months, 2);

                    // Current accumulated from depreciation records
                    $accBefore = (float) FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->sum('amount');

                    $remaining = $asset->acquisition_cost - $accBefore;
                    if ($remaining <= 0) {
                        // Fully depreciated — update status and skip
                        $asset->update(['status' => 'fully_depreciated', 'last_depreciation_period' => $period]);
                        return;
                    }

                    $depAmount = min($monthly, $remaining);
                    $accAfter  = $accBefore + $depAmount;
                    $nbvAfter  = max(0, $asset->acquisition_cost - $accAfter);

                    FixedAssetDepreciation::create([
                        'fixed_asset_id'       => $asset->id,
                        'period'               => $period,
                        'amount'               => $depAmount,
                        'accumulated_before'   => $accBefore,
                        'net_book_value_after' => $nbvAfter,
                    ]);

                    // Keep accumulated_depreciation in sync for balance sheet backward compat
                    $newStatus = $nbvAfter <= 0 ? 'fully_depreciated' : 'active';
                    $asset->update([
                        'accumulated_depreciation' => $accAfter,
                        'last_depreciation_period'  => $period,
                        'status'                    => $newStatus,
                    ]);

                    $processed++;
                });
            } catch (\Throwable $e) {
                $errors[] = "Asset {$asset->code}: " . $e->getMessage();
            }
        }

        return compact('processed', 'skipped', 'errors');
    }

    /**
     * Get depreciation schedule for a single asset (past records + future projection).
     */
    public function getSchedule(FixedAsset $asset): array
    {
        $records = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
            ->orderBy('period')
            ->get();

        $schedule = $records->map(fn ($r) => [
            'period'               => $r->period,
            'amount'               => $r->amount,
            'accumulated_before'   => $r->accumulated_before,
            'accumulated_after'    => $r->accumulated_before + $r->amount,
            'net_book_value_after' => $r->net_book_value_after,
            'posted'               => true,
        ])->toArray();

        // Project remaining periods
        $monthly     = $asset->monthly_depreciation;
        $totalPosted = (float) $records->sum('amount');
        $remaining   = $asset->acquisition_cost - $totalPosted;

        if ($remaining > 0 && $monthly > 0) {
            $lastPeriod = $records->last()?->period
                ?? $asset->acquisition_date?->format('Y-m');

            if ($lastPeriod) {
                $cur = \Carbon\Carbon::createFromFormat('Y-m', $lastPeriod)->addMonth();
                $acc = $totalPosted;

                while ($remaining > 0) {
                    $dep        = min($monthly, $remaining);
                    $acc       += $dep;
                    $remaining -= $dep;

                    $schedule[] = [
                        'period'               => $cur->format('Y-m'),
                        'amount'               => $dep,
                        'accumulated_before'   => $acc - $dep,
                        'accumulated_after'    => $acc,
                        'net_book_value_after' => max(0, $asset->acquisition_cost - $acc),
                        'posted'               => false,
                    ];

                    $cur->addMonth();
                }
            }
        }

        return $schedule;
    }
}
