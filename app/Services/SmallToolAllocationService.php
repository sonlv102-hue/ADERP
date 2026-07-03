<?php

namespace App\Services;

use App\Enums\SmallToolStatus;
use App\Models\AccountingPeriod;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmallToolAllocationService
{
    public function __construct(
        protected SmallToolJournalService $journal,
        protected AccountingService $accountingService,
    ) {}

    // -------------------------------------------------------
    // Tạo lịch phân bổ cho một CCDC
    // -------------------------------------------------------

    public function buildSchedule(SmallTool $tool): void
    {
        if (! $tool->allocation_periods || $tool->allocation_periods <= 0) return;

        $totalCost  = (int) $tool->original_cost;
        $periods    = (int) $tool->allocation_periods;
        $startDate  = Carbon::parse($tool->allocation_start_date ?? $tool->in_service_date ?? now());
        $perPeriod  = (int) floor($totalCost / $periods);
        // Số dư đầu kỳ: bắt đầu từ trạng thái đã phân bổ sẵn (từ hệ thống cũ), không tính lại từ đầu.
        $accumulated = (int) ($tool->total_allocated ?? 0);
        $startIndex  = (int) ($tool->periods_allocated ?? 0);

        // Xóa lịch pending cũ nếu có
        $tool->allocations()->where('status', 'pending')->delete();

        $rows = [];
        for ($i = $startIndex; $i < $periods; $i++) {
            $periodDate  = $startDate->copy()->addMonths($i);
            $periodStr   = $periodDate->format('Y-m');
            $periodStart = $periodDate->startOfMonth()->format('Y-m-d');
            $periodEnd   = $periodDate->endOfMonth()->format('Y-m-d');

            // Kỳ cuối xử lý chênh lệch làm tròn (VND = integer)
            $isLast = ($i === $periods - 1);
            $amount = $isLast ? ($totalCost - $accumulated) : $perPeriod;

            if ($amount <= 0) break;

            $accumulated += $amount;

            $rows[] = [
                'small_tool_id'      => $tool->id,
                'period'             => $periodStr,
                'period_start'       => $periodStart,
                'period_end'         => $periodEnd,
                'amount'             => $amount,
                'accumulated_before' => $accumulated - $amount,
                'remaining_after'    => $totalCost - $accumulated,
                'debit_account'      => $tool->expense_account_code ?: '6422',
                'credit_account'     => $tool->pending_account_code ?: '2422',
                'status'             => 'pending',
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        SmallToolAllocation::insert($rows);
    }

    // -------------------------------------------------------
    // Preview phân bổ cho một kỳ
    // -------------------------------------------------------

    public function previewPeriod(string $period): array
    {
        $tools = SmallTool::with('allocations')
            ->whereIn('status', [SmallToolStatus::Allocating->value])
            ->get();

        $rows = [];
        foreach ($tools as $tool) {
            if ($this->isPausedForPeriod($tool, $period)) continue;

            $alloc = $tool->allocations->where('period', $period)->where('status', 'pending')->first();
            if (! $alloc) continue;

            $rows[] = [
                'tool_id'        => $tool->id,
                'tool_code'      => $tool->code,
                'tool_name'      => $tool->name,
                'department'     => $tool->department,
                'project_id'     => $tool->project_id,
                'debit_account'  => $alloc->debit_account,
                'credit_account' => $alloc->credit_account,
                'amount'         => (float) $alloc->amount,
                'accumulated'    => (float) $alloc->accumulated_before + (float) $alloc->amount,
                'remaining'      => (float) $alloc->remaining_after,
                'alloc_id'       => $alloc->id,
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------
    // Chạy phân bổ tháng (tạo JE theo batch)
    // -------------------------------------------------------

    public function runPeriod(string $period, bool $isDryRun = false): array
    {
        $this->checkPeriodNotClosed($period);

        $tools = SmallTool::with(['allocations', 'project'])
            ->whereIn('status', [SmallToolStatus::Allocating->value])
            ->get();

        $processed = [];
        $skipped   = 0;
        $errors    = [];

        if ($isDryRun) {
            return ['preview' => $this->previewPeriod($period), 'dry_run' => true];
        }

        DB::transaction(function () use ($tools, $period, &$processed, &$skipped, &$errors) {
            $batchItems = [];

            foreach ($tools as $tool) {
                if ($this->isPausedForPeriod($tool, $period)) { $skipped++; continue; }

                $alloc = $tool->allocations->where('period', $period)->where('status', 'pending')->first();
                if (! $alloc) { $skipped++; continue; }

                // Kiểm tra chưa posted trong kỳ này
                $existing = $tool->allocations->where('period', $period)->where('status', 'posted')->first();
                if ($existing) { $skipped++; continue; }

                try {
                    $batchItems[] = ['alloc' => $alloc, 'project_id' => $tool->project_id];
                } catch (\Throwable $e) {
                    $errors[] = ['tool_code' => $tool->code, 'error' => $e->getMessage()];
                }
            }

            if (empty($batchItems)) return;

            // Tạo một JE tổng hợp cho cả kỳ
            $je = $this->journal->createBatchAllocationJournal($batchItems, $period);

            foreach ($batchItems as ['alloc' => $alloc]) {
                $tool = $alloc->tool;

                $alloc->update([
                    'status'          => 'posted',
                    'journal_entry_id' => $je->id,
                    'posted_at'       => now(),
                    'posted_by'       => auth()->id(),
                ]);

                $newAllocated = round((float) $tool->total_allocated + (float) $alloc->amount, 2);
                $newPeriods   = $tool->periods_allocated + 1;
                $remaining    = max(0, (float) $tool->original_cost - $newAllocated);
                $isFullyDone  = $newPeriods >= $tool->allocation_periods || $remaining <= 0;

                $tool->update([
                    'total_allocated'   => $newAllocated,
                    'periods_allocated' => $newPeriods,
                    'status'            => $isFullyDone
                        ? SmallToolStatus::FullyAllocated->value
                        : SmallToolStatus::Allocating->value,
                    'allocation_status' => $isFullyDone ? 'completed' : $tool->allocation_status,
                ]);

                $processed[] = ['tool_code' => $tool->code, 'amount' => (float) $alloc->amount];
            }
        });

        return [
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'period'    => $period,
        ];
    }

    // -------------------------------------------------------
    // Hủy/đảo một kỳ phân bổ đã posted
    // -------------------------------------------------------

    public function reverseAllocation(SmallToolAllocation $alloc): void
    {
        if (! $alloc->isPosted()) {
            throw new \RuntimeException('Chỉ đảo kỳ phân bổ đã được duyệt.');
        }

        $this->checkPeriodNotClosed($alloc->period);

        DB::transaction(function () use ($alloc) {
            $tool = $alloc->tool;

            if ($alloc->journal_entry_id) {
                $je = \App\Models\JournalEntry::find($alloc->journal_entry_id);
                if ($je) $this->accountingService->reverse($je, 'Đảo phân bổ CCDC kỳ ' . $alloc->period);
            }

            $newAllocated  = max(0, (float) $tool->total_allocated - (float) $alloc->amount);
            $newPeriods    = max(0, $tool->periods_allocated - 1);

            $alloc->update([
                'status'      => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
            ]);

            $tool->update([
                'total_allocated'   => $newAllocated,
                'periods_allocated' => $newPeriods,
                'status'            => SmallToolStatus::Allocating->value,
                'allocation_status' => $tool->allocation_status === 'completed' ? 'active' : $tool->allocation_status,
            ]);
        });
    }

    // -------------------------------------------------------
    // Tạm dừng / Tiếp tục phân bổ
    // -------------------------------------------------------

    public function pause(SmallTool $tool, ?string $reason): void
    {
        if (! $tool->canPauseAllocation()) {
            throw new \RuntimeException('CCDC này không ở trạng thái có thể tạm dừng phân bổ.');
        }

        $currentPeriod = now()->format('Y-m');
        $hasPostedCurrentPeriod = $tool->allocations()
            ->where('period', $currentPeriod)->where('status', 'posted')->exists();

        $effective = $hasPostedCurrentPeriod
            ? now()->addMonth()->format('Y-m')
            : $currentPeriod;

        $tool->update([
            'allocation_status'      => 'paused',
            'paused_at'              => now(),
            'paused_by'              => auth()->id(),
            'pause_effective_period' => $effective,
            'pause_reason'           => $reason,
        ]);
    }

    public function resume(SmallTool $tool): array
    {
        if (! $tool->canResumeAllocation()) {
            throw new \RuntimeException('CCDC này không ở trạng thái tạm dừng.');
        }

        $tool->update([
            'allocation_status' => 'active',
            'resumed_at'        => now(),
            'resumed_by'        => auth()->id(),
        ]);

        $nextPending = $tool->allocations()->where('status', 'pending')->orderBy('period')->first();

        return [
            'next_period' => $nextPending?->period,
            'amount'      => $nextPending ? (float) $nextPending->amount : 0,
        ];
    }

    private function isPausedForPeriod(SmallTool $tool, string $period): bool
    {
        if (! $tool->isPaused()) return false;
        return ! $tool->pause_effective_period || $period >= $tool->pause_effective_period;
    }

    // -------------------------------------------------------
    // Helper
    // -------------------------------------------------------

    private function checkPeriodNotClosed(string $period): void
    {
        [$year, $month] = explode('-', $period);
        $ap = AccountingPeriod::where('year', (int) $year)->where('month', (int) $month)->first();
        if ($ap && in_array($ap->status, ['closed', 'locked'])) {
            throw new \RuntimeException("Kỳ kế toán {$period} đã khóa, không thể tạo/hủy phân bổ.");
        }
    }
}
