<?php

namespace App\Services;

use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FixedAssetDepreciationService
{
    public function __construct(
        protected FixedAssetJournalService $journalService,
        protected AccountingService $accountingService,
    ) {}

    // -------------------------------------------------------
    // Preview: tính toán nhưng không ghi DB
    // -------------------------------------------------------

    public function previewPeriod(string $period): array
    {
        $assets = $this->getDepreciableAssets();
        $rows   = [];

        foreach ($assets as $asset) {
            if ($this->alreadyPosted($asset->id, $period)) continue;

            $startDate = $asset->depreciation_start_date ?? $asset->placed_in_service_date ?? $asset->acquisition_date;
            if (! $startDate) continue;

            $periodDate = Carbon::parse($period . '-01');
            if ($startDate->startOfMonth()->gt($periodDate->endOfMonth())) continue;

            $amount = $this->computeAmount($asset, $period);
            if ($amount <= 0) continue;

            $rows[] = [
                'asset_id'              => $asset->id,
                'asset_code'            => $asset->code,
                'asset_name'            => $asset->name,
                'department'            => $asset->department,
                'expense_account'       => $asset->depreciation_expense_account_code ?? '6421',
                'dep_account'           => $asset->getDepreciationAccountCode(),
                'amount'                => $amount,
                'non_deductible_amount' => $this->computeNonDeductibleAmount($asset, $amount),
                'accumulated_before'    => (float) FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->whereIn('status', ['posted', 'planned'])->sum('amount') + (float) $asset->opening_accumulated_depreciation,
                'net_book_value'        => max(0, (float) $asset->acquisition_cost - (float) $asset->accumulated_depreciation - $amount),
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------
    // Run: tạo records + bút toán tổng hợp
    // -------------------------------------------------------

    public function runPeriod(string $period, bool $createJournal = true, bool $isDraft = true): array
    {
        $this->checkPeriodNotClosed($period);

        $assets    = $this->getDepreciableAssets();
        $processed = [];
        $skipped   = 0;
        $errors    = [];

        DB::transaction(function () use ($assets, $period, $createJournal, $isDraft, &$processed, &$skipped, &$errors) {
            foreach ($assets as $asset) {
                if ($this->alreadyPosted($asset->id, $period)) {
                    $skipped++;
                    continue;
                }

                $startDate = $asset->depreciation_start_date ?? $asset->placed_in_service_date ?? $asset->acquisition_date;
                if (! $startDate) { $skipped++; continue; }

                $periodDate = Carbon::parse($period . '-01');
                if ($startDate->startOfMonth()->gt($periodDate->endOfMonth())) { $skipped++; continue; }

                try {
                    $amount = $this->computeAmount($asset, $period);
                    if ($amount <= 0) { $skipped++; continue; }

                    $accBefore = (float) FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                        ->whereIn('status', ['posted', 'planned'])
                        ->sum('amount');
                    $accBefore += (float) $asset->opening_accumulated_depreciation;

                    $remaining = (float) $asset->depreciable_amount - $accBefore;
                    if ($remaining <= 0) {
                        $asset->update(['status' => FixedAssetStatus::FullyDepreciated->value]);
                        $skipped++;
                        continue;
                    }

                    $amount = min($amount, $remaining);
                    $accAfter = $accBefore + $amount;
                    $nbvAfter = max(0, (float) $asset->acquisition_cost - $accAfter);

                    $dep = FixedAssetDepreciation::create([
                        'fixed_asset_id'        => $asset->id,
                        'period'                => $period,
                        'period_start'          => Carbon::parse($period . '-01'),
                        'period_end'            => Carbon::parse($period . '-01')->endOfMonth(),
                        'amount'                => $amount,
                        'non_deductible_amount' => $this->computeNonDeductibleAmount($asset, $amount),
                        'accumulated_before'    => $accBefore,
                        'net_book_value_after'  => $nbvAfter,
                        'status'                => 'planned',
                    ]);

                    $newStatus = $nbvAfter <= 0 ? FixedAssetStatus::FullyDepreciated->value : $asset->status->value;
                    $asset->update([
                        'accumulated_depreciation' => $accAfter,
                        'last_depreciation_period' => $period,
                        'status'                   => $newStatus,
                    ]);

                    $processed[] = ['asset' => $asset, 'dep' => $dep, 'amount' => $amount];
                } catch (\Throwable $e) {
                    $errors[] = "{$asset->code}: " . $e->getMessage();
                }
            }

            // Tạo một bút toán tổng hợp cho toàn bộ tài sản đã tính
            if ($createJournal && count($processed) > 0) {
                $items = array_map(fn ($r) => ['asset' => $r['asset'], 'amount' => $r['amount']], $processed);
                $je    = $this->journalService->createBatchDepreciationJournal($items, $period, $isDraft);

                // Gán journal_entry_id và cập nhật status cho từng record
                foreach ($processed as &$row) {
                    $row['dep']->update([
                        'journal_entry_id' => $je->id,
                        'status'           => $isDraft ? 'planned' : 'posted',
                        'posted_at'        => $isDraft ? null : now(),
                        'posted_by'        => auth()->id(),
                    ]);
                }

                $processed = array_map(fn ($r) => [
                    'asset_id'   => $r['asset']->id,
                    'asset_code' => $r['asset']->code,
                    'amount'     => $r['amount'],
                    'dep_id'     => $r['dep']->id,
                    'je_id'      => $je->id,
                ], $processed);
            }
        });

        return [
            'processed' => count($processed),
            'skipped'   => $skipped,
            'errors'    => $errors,
            'rows'      => $processed,
        ];
    }

    // -------------------------------------------------------
    // Post bút toán đã draft
    // -------------------------------------------------------

    public function postDepreciationJournal(JournalEntry $je): void
    {
        $this->accountingService->markPosted($je);

        FixedAssetDepreciation::where('journal_entry_id', $je->id)
            ->update([
                'status'    => 'posted',
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);
    }

    // -------------------------------------------------------
    // Hủy khấu hao kỳ chưa khóa
    // -------------------------------------------------------

    public function reverseDepreciation(FixedAssetDepreciation $dep): void
    {
        if ($dep->isReversed()) {
            throw new \RuntimeException('Bút toán này đã được hủy trước đó.');
        }

        $this->checkPeriodNotClosed($dep->period);

        DB::transaction(function () use ($dep) {
            $asset = $dep->fixedAsset;

            // Hủy bút toán JE nếu tồn tại
            if ($dep->journal_entry_id) {
                $this->accountingService->reverseOrDelete(
                    'fixed_asset_depreciation_batch',
                    $dep->id,
                    'Hủy khấu hao tháng ' . $dep->period
                );
            }

            // Rollback accumulated
            $asset->decrement('accumulated_depreciation', (float) $dep->amount);
            if ($asset->status === FixedAssetStatus::FullyDepreciated) {
                $asset->update(['status' => FixedAssetStatus::Active->value]);
            }

            $dep->update([
                'status'      => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
            ]);
        });
    }

    // -------------------------------------------------------
    // Schedule projection (dùng cho Show page)
    // -------------------------------------------------------

    public function getFullSchedule(FixedAsset $asset): array
    {
        $posted = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
            ->orderBy('period')
            ->get();

        $schedule = $posted->map(fn ($r) => [
            'period'              => $r->period,
            'amount'              => (float) $r->amount,
            'accumulated_before'  => (float) $r->accumulated_before,
            'accumulated_after'   => (float) $r->accumulated_before + (float) $r->amount,
            'net_book_value_after' => (float) $r->net_book_value_after,
            'status'              => $r->status,
            'journal_entry_id'    => $r->journal_entry_id,
            'posted'              => $r->isPosted(),
        ])->toArray();

        // Project remaining
        $base      = (float) $asset->depreciable_amount ?: (float) $asset->acquisition_cost;
        $monthly   = $asset->useful_life_months > 0 ? round($base / $asset->useful_life_months, 2) : 0;
        $totalPost = (float) $posted->where('status', 'posted')->sum('amount');
        $remaining = $base - $totalPost - (float) $asset->opening_accumulated_depreciation;

        if ($remaining > 0 && $monthly > 0) {
            $lastPeriod = $posted->last()?->period
                ?? ($asset->depreciation_start_date ?? $asset->placed_in_service_date ?? $asset->acquisition_date)?->format('Y-m');

            if ($lastPeriod) {
                $cur = Carbon::createFromFormat('Y-m', $lastPeriod)->addMonth();
                $acc = $totalPost + (float) $asset->opening_accumulated_depreciation;

                while ($remaining > 0.01) {
                    $dep        = min($monthly, $remaining);
                    $acc       += $dep;
                    $remaining -= $dep;

                    $schedule[] = [
                        'period'               => $cur->format('Y-m'),
                        'amount'               => $dep,
                        'accumulated_before'   => $acc - $dep,
                        'accumulated_after'    => $acc,
                        'net_book_value_after' => max(0, (float) $asset->acquisition_cost - $acc),
                        'status'               => 'planned',
                        'journal_entry_id'     => null,
                        'posted'               => false,
                    ];

                    $cur->addMonth();
                    if ($cur->year > now()->year + 50) break; // safety
                }
            }
        }

        return $schedule;
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function getDepreciableAssets(): Collection
    {
        return FixedAsset::whereNull('deleted_at')
            ->where('status', FixedAssetStatus::Active->value)
            ->where('useful_life_months', '>', 0)
            ->where('acquisition_cost', '>', 0)
            ->get();
    }

    private function alreadyPosted(int $assetId, string $period): bool
    {
        return FixedAssetDepreciation::where('fixed_asset_id', $assetId)
            ->where('period', $period)
            ->whereIn('status', ['posted', 'planned'])
            ->exists();
    }

    private function computeAmount(FixedAsset $asset, string $period): float
    {
        $base = (float) $asset->depreciable_amount ?: (float) $asset->acquisition_cost;
        return $asset->useful_life_months > 0
            ? round($base / $asset->useful_life_months, 2)
            : 0;
    }

    /**
     * Phần khấu hao không được trừ thuế TNDN trong kỳ.
     * Áp dụng cho xe ô tô chở người ≤9 chỗ có nguyên giá vượt 1.600.000.000 VND.
     * Hạch toán vẫn Dr toàn bộ vào TK chi phí / Có 2141; phần này chỉ dùng cho báo cáo thuế.
     */
    private function computeNonDeductibleAmount(FixedAsset $asset, float $totalAmount): float
    {
        if (! $asset->is_sedan_under_9_seats) return 0;
        if ($asset->useful_life_months <= 0) return 0;

        $taxDeductibleBase = (float) ($asset->tax_deductible_cost ?? $asset->acquisition_cost);
        $taxDeductibleMonthly = round($taxDeductibleBase / $asset->useful_life_months, 2);

        return max(0, round($totalAmount - $taxDeductibleMonthly, 2));
    }

    private function checkPeriodNotClosed(string $period): void
    {
        $year  = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $ap    = AccountingPeriod::where('year', $year)->where('month', $month)->first();
        if ($ap && $ap->status === 'closed') {
            throw new \RuntimeException("Kỳ kế toán {$period} đã khóa. Không thể thay đổi bút toán khấu hao.");
        }
    }
}
