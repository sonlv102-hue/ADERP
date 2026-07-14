<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Báo cáo lợi nhuận quản trị (không phải mẫu B02-DNN thống kê).
 * Tách riêng chi phí bán hàng (641) và chi phí quản lý (642), có margin %.
 * Chỉ lấy bút toán GL đã posted, theo entry_date — không dùng created_at.
 */
class ProfitReportService
{
    public function buildSummary(array $filters): array
    {
        [$from, $to] = $this->range($filters);

        return $this->summaryForRange($from, $to);
    }

    /**
     * Chia khoảng [from, to] thành các dòng theo tháng (kể cả khi khoảng chọn
     * là 1 tháng/1 quý/tùy chọn nhiều tháng) — mỗi dòng tính lại summaryForRange
     * trên đúng phần tháng đó, cắt theo biên from/to gốc.
     */
    public function buildRowsByPeriod(array $filters): array
    {
        [$from, $to] = $this->range($filters);

        $rows   = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor <= $to) {
            $bucketFrom = $cursor->copy()->max($from);
            $bucketTo   = $cursor->copy()->endOfMonth()->min($to);

            $row         = $this->summaryForRange($bucketFrom, $bucketTo);
            $row['label'] = $cursor->format('m/Y');
            $rows[]       = $row;

            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $rows;
    }

    public function getRevenue(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '511');
    }

    public function getDeductions(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '521');
    }

    public function getCogs(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '632');
    }

    public function getSellingExpenses(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '641');
    }

    public function getAdminExpenses(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '642');
    }

    public function getFinancialExpenses(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '635');
    }

    public function getOtherExpenses(array $filters): float
    {
        [$from, $to] = $this->range($filters);
        return $this->sumPrefix($this->periodBalances($from, $to), '811');
    }

    public function calculateMargins(array $row): array
    {
        $netRevenue = $row['net_revenue'] ?? 0.0;

        $row['gross_margin'] = $netRevenue > 0 ? round($row['gross_profit'] / $netRevenue * 100, 2) : null;
        $row['net_margin']   = $netRevenue > 0 ? round($row['net_profit'] / $netRevenue * 100, 2) : null;

        return $row;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function summaryForRange(Carbon $from, Carbon $to): array
    {
        $bal = $this->periodBalances($from, $to);
        $b   = fn (string $prefix) => $this->sumPrefix($bal, $prefix);

        $revenue    = $b('511');
        $deductions = $b('521');
        $netRevenue = $revenue - $deductions;
        $cogs       = $b('632');
        $grossProfit = $netRevenue - $cogs;
        $selling    = $b('641');
        $admin      = $b('642');
        $financial  = $b('635');
        $other      = $b('811');
        $totalOpex  = $selling + $admin + $financial + $other;
        $netProfit  = $grossProfit - $totalOpex;

        return $this->calculateMargins([
            'revenue'                 => $revenue,
            'deductions'              => $deductions,
            'net_revenue'             => $netRevenue,
            'cogs'                    => $cogs,
            'gross_profit'            => $grossProfit,
            'selling_expense'         => $selling,
            'admin_expense'           => $admin,
            'financial_expense'       => $financial,
            'other_expense'           => $other,
            'total_operating_expense' => $totalOpex,
            'net_profit'              => $netProfit,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(array $filters): array
    {
        return [
            Carbon::parse($filters['date_from'])->startOfDay(),
            Carbon::parse($filters['date_to'])->endOfDay(),
        ];
    }

    /**
     * Một chiều theo normal_balance: revenue TK → Cr side; expense TK → Dr side.
     * Chỉ lấy JE posted; bút toán đảo chuyển JE gốc sang status 'reversed'
     * (bị loại khỏi filter này) nên không bị đếm trùng.
     */
    private function periodBalances(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->select('jel.account_code', 'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr'))
            ->groupBy('jel.account_code', 'ac.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr
                : (float) $r->cr;
        }
        return $result;
    }

    private function sumPrefix(array $balances, string $prefix): float
    {
        $total = 0.0;
        foreach ($balances as $code => $amount) {
            if (str_starts_with((string) $code, $prefix)) {
                $total += $amount;
            }
        }
        return $total;
    }
}
