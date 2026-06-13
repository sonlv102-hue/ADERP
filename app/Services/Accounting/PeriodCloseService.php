<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kết chuyển cuối kỳ TT133:
 *   1. Kết chuyển doanh thu (5xx/7xx) → TK 911
 *   2. Kết chuyển chi phí (6xx/8xx)   → TK 911
 *   3. Kết chuyển lợi nhuận 911       → TK 4212
 *
 * Idempotent: kiểm tra JE source_type='period_close' trước khi tạo.
 */
class PeriodCloseService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Thực hiện kết chuyển cho kỳ $period (YYYY-MM).
     * Trả về danh sách JE đã tạo.
     *
     * @throws \RuntimeException nếu kỳ không tồn tại, đã đóng, hoặc đã kết chuyển
     */
    public function close(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        $accountingPeriod = AccountingPeriod::where('year', $year)->where('month', $month)->first();
        if (!$accountingPeriod || $accountingPeriod->status !== 'open') {
            throw new \RuntimeException("Kỳ kế toán {$period} không tồn tại hoặc đã đóng/khóa.");
        }

        if ($this->alreadyClosed($period, $accountingPeriod->id)) {
            throw new \RuntimeException("Kỳ {$period} đã được kết chuyển trước đó. Hủy bút toán kết chuyển cũ trước nếu muốn làm lại.");
        }

        // Lấy số dư của từng TK doanh thu / chi phí trong kỳ
        $balances = $this->getPeriodBalances($period);

        $revenueLines = [];   // Dr revenue accounts / Cr 911
        $expenseLines = [];   // Dr 911 / Cr expense accounts
        $totalRevenue = 0;
        $totalExpense = 0;

        foreach ($balances as $code => $b) {
            $type          = $b['type'];
            $normalBalance = $b['normal_balance'];

            // Số dư thuần của tài khoản trong kỳ
            $net = $b['total_debit'] - $b['total_credit'];   // dương = debit excess, âm = credit excess

            if ($type === 'revenue') {
                // Doanh thu: credit excess = dương theo chiều credit → $creditNet = $b['total_credit'] - $b['total_debit']
                $creditNet = $b['total_credit'] - $b['total_debit'];
                if ($creditNet <= 0) continue;
                $revenueLines[] = ['account' => $code,  'debit' => (int) round($creditNet), 'credit' => 0,
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $revenueLines[] = ['account' => '911',  'debit' => 0, 'credit' => (int) round($creditNet),
                                   'description' => "KC doanh thu {$code} kỳ {$period}"];
                $totalRevenue  += $creditNet;
            }

            if ($type === 'expense') {
                // Chi phí: debit excess
                $debitNet = $b['total_debit'] - $b['total_credit'];
                if ($debitNet <= 0) continue;
                $expenseLines[] = ['account' => '911',  'debit' => (int) round($debitNet), 'credit' => 0,
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $expenseLines[] = ['account' => $code,  'debit' => 0, 'credit' => (int) round($debitNet),
                                   'description' => "KC chi phí {$code} kỳ {$period}"];
                $totalExpense  += $debitNet;
            }
        }

        $createdJes = [];
        $entryDate  = Carbon::parse("{$period}-01")->endOfMonth()->startOfDay();

        // 1. Kết chuyển doanh thu
        if (!empty($revenueLines) && count($revenueLines) >= 2) {
            $je = $this->accounting->post(
                "Kết chuyển doanh thu kỳ {$period}",
                $entryDate,
                $revenueLines,
                'accounting_period', $accountingPeriod->id,
                false, null, 'period_close'
            );
            $createdJes[] = $je;
        }

        // 2. Kết chuyển chi phí
        if (!empty($expenseLines) && count($expenseLines) >= 2) {
            $je = $this->accounting->post(
                "Kết chuyển chi phí kỳ {$period}",
                $entryDate,
                $expenseLines,
                'accounting_period', $accountingPeriod->id,
                false, null, 'period_close'
            );
            $createdJes[] = $je;
        }

        // 3. Kết chuyển lợi nhuận / lỗ 911 → 4212
        $profit = (int) round($totalRevenue - $totalExpense);
        if ($profit !== 0) {
            if ($profit > 0) {
                // Lợi nhuận: Dr 911 / Cr 4212
                $profitLines = [
                    ['account' => '911',  'debit' => $profit, 'credit' => 0,       'description' => "KC lợi nhuận kỳ {$period}"],
                    ['account' => '4212', 'debit' => 0,       'credit' => $profit,  'description' => "KC lợi nhuận kỳ {$period}"],
                ];
            } else {
                // Lỗ: Dr 4212 / Cr 911
                $loss = abs($profit);
                $profitLines = [
                    ['account' => '4212', 'debit' => $loss, 'credit' => 0,    'description' => "KC lỗ kỳ {$period}"],
                    ['account' => '911',  'debit' => 0,     'credit' => $loss, 'description' => "KC lỗ kỳ {$period}"],
                ];
            }

            $je = $this->accounting->post(
                ($profit > 0 ? "Kết chuyển lợi nhuận" : "Kết chuyển lỗ") . " kỳ {$period}",
                $entryDate,
                $profitLines,
                'accounting_period', $accountingPeriod->id,
                false, null, 'period_close'
            );
            $createdJes[] = $je;
        }

        return $createdJes;
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

    /**
     * Lấy số dư các TK doanh thu/chi phí trong kỳ (chỉ JE posted, loại trừ kết chuyển cũ).
     */
    private function getPeriodBalances(string $period): array
    {
        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jl.account_code')
            ->where('je.status', 'posted')
            ->where('je.fiscal_period', $period)
            ->whereIn('ac.type', ['revenue', 'expense'])
            ->where('ac.is_detail', true)
            ->where(function ($q) {                         // bỏ qua kết chuyển cũ nếu có; NULL vẫn được tính
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
                'type'          => $r->type,
                'normal_balance'=> $r->normal_balance,
                'total_debit'   => (float) $r->total_debit,
                'total_credit'  => (float) $r->total_credit,
            ];
        }
        return $result;
    }
}
