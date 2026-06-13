<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PeriodCloseBatch;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kết chuyển cuối kỳ TT133.
 *
 * Public API:
 *   buildClosingPlan(string $period): array       — dry-run, không write DB
 *   getWarnings(string $period): array            — kiểm tra cảnh báo
 *   preview(string $period): array                — plan + warnings
 *   closeWithBatch(string $period, int $userId, ?string $notes): PeriodCloseBatch
 *   reverseBatch(PeriodCloseBatch $batch, int $userId, string $reason): void
 *   close(string $period): array                  — legacy CLI compat, không tạo batch
 */
class PeriodCloseService
{
    public function __construct(private AccountingService $accounting) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tính toán kế hoạch kết chuyển cho kỳ $period (YYYY-MM).
     * Không tạo bất kỳ record nào trong DB.
     */
    public function buildClosingPlan(string $period): array
    {
        $balances   = $this->getPeriodBalances($period);
        $entryDate  = Carbon::parse("{$period}-01")->endOfMonth()->startOfDay();
        $accountMap = $this->getAccountNames(array_keys($balances));

        $revenueLines = [];
        $expenseLines = [];
        $accountLines = [];
        $totalRevenue = 0;
        $totalExpense = 0;

        foreach ($balances as $code => $b) {
            $name = $accountMap[$code] ?? $code;

            if ($b['type'] === 'revenue') {
                $amount = (int) round($b['total_credit'] - $b['total_debit']);
                if ($amount <= 0) continue;

                $revenueLines[] = ['account' => $code,  'debit' => $amount, 'credit' => 0,
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $revenueLines[] = ['account' => '911',  'debit' => 0,       'credit' => $amount,
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $accountLines[] = [
                    'code'          => $code,
                    'name'          => $name,
                    'type'          => 'revenue',
                    'amount'        => $amount,
                    'journal_debit' => $amount,
                    'journal_credit'=> 0,
                    'counterpart'   => '911',
                    'entry_text'    => "Nợ {$code} / Có 911",
                ];
                $totalRevenue += $amount;
            }

            if ($b['type'] === 'expense') {
                $amount = (int) round($b['total_debit'] - $b['total_credit']);
                if ($amount <= 0) continue;

                $expenseLines[] = ['account' => '911',  'debit' => $amount, 'credit' => 0,
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $expenseLines[] = ['account' => $code,  'debit' => 0,       'credit' => $amount,
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $accountLines[] = [
                    'code'          => $code,
                    'name'          => $name,
                    'type'          => 'expense',
                    'amount'        => $amount,
                    'journal_debit' => 0,
                    'journal_credit'=> $amount,
                    'counterpart'   => '911',
                    'entry_text'    => "Nợ 911 / Có {$code}",
                ];
                $totalExpense += $amount;
            }
        }

        $profitOrLoss = $totalRevenue - $totalExpense;
        $profitLines  = [];

        if ($profitOrLoss > 0) {
            $profitLines = [
                ['account' => '911',  'debit' => $profitOrLoss, 'credit' => 0,
                 'description' => "KC lợi nhuận kỳ {$period}"],
                ['account' => '4212', 'debit' => 0,             'credit' => $profitOrLoss,
                 'description' => "KC lợi nhuận kỳ {$period}"],
            ];
        } elseif ($profitOrLoss < 0) {
            $loss = abs($profitOrLoss);
            $profitLines = [
                ['account' => '4212', 'debit' => $loss, 'credit' => 0,
                 'description' => "KC lỗ kỳ {$period}"],
                ['account' => '911',  'debit' => 0,     'credit' => $loss,
                 'description' => "KC lỗ kỳ {$period}"],
            ];
        }

        return [
            'period'        => $period,
            'entryDate'     => $entryDate,
            'accountLines'  => $accountLines,
            'revenueLines'  => $revenueLines,
            'expenseLines'  => $expenseLines,
            'profitLines'   => $profitLines,
            'totalRevenue'  => $totalRevenue,
            'totalExpense'  => $totalExpense,
            'profitOrLoss'  => $profitOrLoss,
        ];
    }

    /**
     * Trả về danh sách cảnh báo trước khi kết chuyển.
     * ['type' => 'critical|warning|info', 'code' => string, 'message' => string]
     */
    public function getWarnings(string $period, ?AccountingPeriod $accountingPeriod = null): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = $accountingPeriod ?? AccountingPeriod::where('year', $year)->where('month', $month)->first();

        $warnings = [];

        if (!$ap) {
            $warnings[] = ['type' => 'critical', 'code' => 'no_period',
                           'message' => "Kỳ kế toán {$period} chưa được tạo."];
            return $warnings;
        }

        if ($ap->status === 'locked') {
            $warnings[] = ['type' => 'critical', 'code' => 'period_locked',
                           'message' => "Kỳ {$period} đã khóa. Không thể kết chuyển."];
        }

        if ($this->hasActiveBatch($ap->id)) {
            $warnings[] = ['type' => 'critical', 'code' => 'batch_exists',
                           'message' => "Đã tồn tại bút toán kết chuyển active trong kỳ {$period}. Hủy/đảo batch cũ trước."];
        }

        $draftCount = JournalEntry::where('fiscal_period', $period)
            ->where('status', 'draft')
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'period_close'))
            ->count();
        if ($draftCount > 0) {
            $warnings[] = ['type' => 'warning', 'code' => 'draft_entries',
                           'message' => "Có {$draftCount} bút toán nháp chưa duyệt trong kỳ. Kết chuyển có thể thiếu phát sinh."];
        }

        $parentEntryCount = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jl.account_code')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('ac.is_detail', false)
            ->where(fn ($q) => $q->whereNull('je.source_type')->orWhere('je.source_type', '!=', 'period_close'))
            ->count();
        if ($parentEntryCount > 0) {
            $warnings[] = ['type' => 'warning', 'code' => 'parent_account_entries',
                           'message' => "Có {$parentEntryCount} dòng bút toán đang dùng tài khoản tổng hợp. Các dòng này sẽ không được kết chuyển."];
        }

        $tk641Count = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('jl.account_code', 'like', '641%')
            ->count();
        if ($tk641Count > 0) {
            $warnings[] = ['type' => 'warning', 'code' => 'tk641_exists',
                           'message' => "Có phát sinh TK 641 trong kỳ ({$tk641Count} dòng). Hệ thống áp dụng TT133 dùng TK 6421. Kiểm tra lại nguồn gốc bút toán."];
        }

        $endOfMonth = Carbon::parse("{$period}-01")->endOfMonth()->format('Y-m-d');
        $commissionCount = DB::table('commissions')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', ["{$period}-01 00:00:00", "{$endOfMonth} 23:59:59"])
            ->count();
        if ($commissionCount > 0) {
            $warnings[] = ['type' => 'info', 'code' => 'commissions_check',
                           'message' => "Có {$commissionCount} hoa hồng trong kỳ. Xác nhận đã hạch toán vào TK 6421 trước khi kết chuyển."];
        }

        $wipBalance = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->where('jl.account_code', '154')
            ->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) as net')
            ->value('net');
        if ($wipBalance > 0) {
            $warnings[] = ['type' => 'info', 'code' => 'wip_balance',
                           'message' => 'Có chi phí dở dang TK 154 trong kỳ. TK 154 sẽ không được kết chuyển — chỉ kết chuyển sau nghiệm thu dự án.'];
        }

        return $warnings;
    }

    /**
     * Dry-run: trả về plan + warnings để hiển thị trên UI.
     */
    public function preview(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        $plan     = $this->buildClosingPlan($period);
        $warnings = $this->getWarnings($period, $ap);
        $hasCritical = collect($warnings)->where('type', 'critical')->isNotEmpty();

        return array_merge($plan, [
            'warnings'    => $warnings,
            'hasCritical' => $hasCritical,
            'canClose'    => !$hasCritical && $ap && $ap->status === 'open',
            'periodStatus'=> $ap?->status ?? 'not_found',
        ]);
    }

    /**
     * Tạo batch kết chuyển với journal entries trong một transaction.
     *
     * @throws \RuntimeException nếu có cảnh báo Critical
     */
    public function closeWithBatch(string $period, int $userId, ?string $notes = null): PeriodCloseBatch
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        if (!$ap || $ap->status !== 'open') {
            throw new \RuntimeException("Kỳ kế toán {$period} không tồn tại hoặc đã đóng/khóa.");
        }

        $warnings  = $this->getWarnings($period, $ap);
        $criticals = collect($warnings)->where('type', 'critical');
        if ($criticals->isNotEmpty()) {
            throw new \RuntimeException($criticals->pluck('message')->implode(' | '));
        }

        $plan = $this->buildClosingPlan($period);

        if (empty($plan['revenueLines']) && empty($plan['expenseLines'])) {
            throw new \RuntimeException("Không có phát sinh doanh thu/chi phí trong kỳ {$period}.");
        }

        return DB::transaction(function () use ($period, $userId, $notes, $plan, $ap) {
            $batch = PeriodCloseBatch::create([
                'code'                 => PeriodCloseBatch::generateCode($period),
                'accounting_period_id' => $ap->id,
                'fiscal_period'        => $period,
                'status'               => 'draft',
                'total_revenue'        => $plan['totalRevenue'],
                'total_expense'        => $plan['totalExpense'],
                'profit_or_loss'       => $plan['profitOrLoss'],
                'notes'                => $notes,
                'created_by'           => $userId,
            ]);

            $jeCount   = 0;
            $entryDate = $plan['entryDate'];

            if (!empty($plan['revenueLines'])) {
                $je = $this->accounting->post(
                    "Kết chuyển doanh thu kỳ {$period}", $entryDate, $plan['revenueLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            if (!empty($plan['expenseLines'])) {
                $je = $this->accounting->post(
                    "Kết chuyển chi phí kỳ {$period}", $entryDate, $plan['expenseLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            if (!empty($plan['profitLines'])) {
                $desc = $plan['profitOrLoss'] > 0
                    ? "Kết chuyển lợi nhuận kỳ {$period}"
                    : "Kết chuyển lỗ kỳ {$period}";
                $je = $this->accounting->post(
                    $desc, $entryDate, $plan['profitLines'],
                    'accounting_period', $ap->id, false, null, 'period_close', false, $period
                );
                $je->update(['period_close_batch_id' => $batch->id]);
                $jeCount++;
            }

            $batch->update([
                'status'              => 'posted',
                'journal_entry_count' => $jeCount,
                'posted_by'           => $userId,
                'posted_at'           => now(),
            ]);

            return $batch;
        });
    }

    /**
     * Đảo toàn bộ batch kết chuyển trong một transaction.
     *
     * @throws \RuntimeException nếu kỳ đã khóa hoặc batch không thể đảo
     */
    public function reverseBatch(PeriodCloseBatch $batch, int $userId, string $reason): void
    {
        if ($batch->status !== 'posted') {
            throw new \RuntimeException("Chỉ có thể đảo batch ở trạng thái 'Đã kết chuyển'.");
        }

        $ap = $batch->accountingPeriod;
        if ($ap && $ap->status === 'locked') {
            throw new \RuntimeException("Kỳ {$batch->fiscal_period} đã khóa. Không thể đảo bút toán kết chuyển.");
        }

        DB::transaction(function () use ($batch, $userId, $reason) {
            $entries = $batch->journalEntries()
                ->where('status', 'posted')
                ->with('lines')
                ->get();

            foreach ($entries as $je) {
                $reversal = $this->accounting->reverse($je, $reason);
                $reversal->update(['period_close_batch_id' => $batch->id]);
            }

            $batch->update([
                'status'         => 'reversed',
                'reversed_by'    => $userId,
                'reversed_at'    => now(),
                'reverse_reason' => $reason,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Legacy — backward compat với CLI command (không tạo batch)
    // ─────────────────────────────────────────────────────────────────────────

    /** @deprecated Dùng closeWithBatch() cho UI. Method này giữ cho CLI command. */
    public function close(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $ap = AccountingPeriod::where('year', $year)->where('month', $month)->first();

        if (!$ap || $ap->status !== 'open') {
            throw new \RuntimeException("Kỳ kế toán {$period} không tồn tại hoặc đã đóng/khóa.");
        }

        if ($this->alreadyClosed($period, $ap->id)) {
            throw new \RuntimeException("Kỳ {$period} đã được kết chuyển trước đó. Hủy bút toán kết chuyển cũ trước nếu muốn làm lại.");
        }

        $plan = $this->buildClosingPlan($period);
        $createdJes = [];

        if (!empty($plan['revenueLines'])) {
            $createdJes[] = $this->accounting->post(
                "Kết chuyển doanh thu kỳ {$period}", $plan['entryDate'], $plan['revenueLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }
        if (!empty($plan['expenseLines'])) {
            $createdJes[] = $this->accounting->post(
                "Kết chuyển chi phí kỳ {$period}", $plan['entryDate'], $plan['expenseLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }
        if (!empty($plan['profitLines'])) {
            $desc = $plan['profitOrLoss'] > 0 ? "Kết chuyển lợi nhuận kỳ {$period}" : "Kết chuyển lỗ kỳ {$period}";
            $createdJes[] = $this->accounting->post(
                $desc, $plan['entryDate'], $plan['profitLines'],
                'accounting_period', $ap->id, false, null, 'period_close', false, $period
            );
        }

        return $createdJes;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function hasActiveBatch(int $periodId): bool
    {
        return PeriodCloseBatch::where('accounting_period_id', $periodId)
            ->whereIn('status', ['draft', 'posted'])
            ->exists();
    }

    private function alreadyClosed(string $period, int $periodId): bool
    {
        return JournalEntry::where('source_type', 'period_close')
            ->where('reference_type', 'accounting_period')
            ->where('reference_id', $periodId)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->exists();
    }

    private function getPeriodBalances(string $period): array
    {
        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jl.account_code')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->whereIn('ac.type', ['revenue', 'expense'])
            ->where('ac.is_detail', true)
            ->where(function ($q) {
                $q->whereNull('je.source_type')
                  ->orWhere('je.source_type', '!=', 'period_close');
            })
            ->groupBy('jl.account_code', 'ac.type', 'ac.normal_balance')
            ->select(
                'jl.account_code',
                'ac.type',
                'ac.normal_balance',
                DB::raw('SUM(jl.debit) as total_debit'),
                DB::raw('SUM(jl.credit) as total_credit')
            )
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = [
                'type'           => $r->type,
                'normal_balance' => $r->normal_balance,
                'total_debit'    => (float) $r->total_debit,
                'total_credit'   => (float) $r->total_credit,
            ];
        }
        return $result;
    }

    private function getAccountNames(array $codes): array
    {
        if (empty($codes)) return [];
        return DB::table('account_codes')
            ->whereIn('code', $codes)
            ->pluck('name', 'code')
            ->all();
    }
}
