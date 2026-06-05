<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\IncomeStatementExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncomeStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";

        $bal = $this->periodBalances($dateFrom, $dateTo);
        $b   = fn(string $prefix) => $this->sumPrefix($bal, $prefix);

        // Doanh thu (TK 511 + TK 512, loại trừ TK 515 để tránh double-count)
        $revenue      = $b('511') + $b('512');
        $salesReturn  = $b('521');  // Giảm trừ doanh thu
        $netRevenue   = $revenue - $salesReturn;

        // Giá vốn
        $cogs         = $b('632');

        // Lợi nhuận gộp
        $grossProfit  = $netRevenue - $cogs;
        $grossMargin  = $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 1) : null;

        // Chi phí hoạt động
        $financialIncome  = $b('515');
        $financialExpense = $b('635');
        $sellingExpense   = $b('641');
        $adminExpense     = $b('642');
        $otherIncome      = $b('711');
        $otherExpense     = $b('811');

        $netOpProfit    = $grossProfit + $financialIncome - $financialExpense - $sellingExpense - $adminExpense;
        $ebt            = $netOpProfit + $otherIncome - $otherExpense;
        $cit            = $b('8211');  // Thuế TNDN hiện hành
        $netProfit      = $ebt - $cit;

        // Breakdown theo tháng — 1 query duy nhất
        $monthly = $this->monthlyBreakdown($year);

        $statement = [
            ['label' => 'Doanh thu bán hàng và CCDV',                'amount' => $revenue,          'bold' => false, 'indent' => 0],
            ['label' => '  Các khoản giảm trừ doanh thu (TK 521)',   'amount' => -$salesReturn,     'bold' => false, 'indent' => 1],
            ['label' => 'Doanh thu thuần',                            'amount' => $netRevenue,       'bold' => true,  'indent' => 0],
            ['label' => 'Giá vốn hàng bán (TK 632)',                 'amount' => -$cogs,            'bold' => false, 'indent' => 1],
            ['label' => 'Lợi nhuận gộp',                             'amount' => $grossProfit,      'bold' => true,  'indent' => 0],
            ['label' => 'Doanh thu hoạt động tài chính (TK 515)',    'amount' => $financialIncome,  'bold' => false, 'indent' => 1],
            ['label' => 'Chi phí tài chính (TK 635)',                 'amount' => -$financialExpense,'bold' => false, 'indent' => 1],
            ['label' => 'Chi phí bán hàng (TK 641)',                  'amount' => -$sellingExpense,  'bold' => false, 'indent' => 1],
            ['label' => 'Chi phí QLDN (TK 642)',                      'amount' => -$adminExpense,    'bold' => false, 'indent' => 1],
            ['label' => 'Lợi nhuận thuần từ HĐKD',                   'amount' => $netOpProfit,      'bold' => true,  'indent' => 0],
            ['label' => 'Thu nhập khác (TK 711)',                     'amount' => $otherIncome,      'bold' => false, 'indent' => 1],
            ['label' => 'Chi phí khác (TK 811)',                      'amount' => -$otherExpense,    'bold' => false, 'indent' => 1],
            ['label' => 'Lợi nhuận trước thuế',                      'amount' => $ebt,              'bold' => true,  'indent' => 0],
            ['label' => 'Thuế TNDN (TK 8211)',                        'amount' => -$cit,             'bold' => false, 'indent' => 1],
            ['label' => 'Lợi nhuận sau thuế',                        'amount' => $netProfit,        'bold' => true,  'indent' => 0],
        ];

        $summary = [
            'revenue'        => $revenue,
            'vat_out'        => 0,
            'total_cogs'     => $cogs,
            'gross_profit'   => $grossProfit,
            'gross_margin'   => $grossMargin,
            'net_profit'     => $netProfit,
            'net_op_profit'  => $netOpProfit,
            'ebt'            => $ebt,
            'vat_in'         => 0,
            'purchase_total' => 0,
        ];

        return Inertia::render('Reports/IncomeStatement/Index', [
            'statement'   => $statement,
            'monthly'     => $monthly,
            'summary'     => $summary,
            'filters'     => $request->only(['year', 'date_from', 'date_to']),
            'currentYear' => $year,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Map [account_code => net_balance] cho các TK P&L trong kỳ */
    private function periodBalances(string $from, string $to): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->select('jel.account_code', 'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr'))
            ->groupBy('jel.account_code', 'ac.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr - (float) $r->cr
                : (float) $r->cr - (float) $r->dr;
        }
        return $result;
    }

    /** Breakdown theo tháng — 1 query duy nhất */
    private function monthlyBreakdown(int $year): array
    {
        $from = "{$year}-01-01";
        $to   = "{$year}-12-31";

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereRaw("(jel.account_code LIKE '5%' OR jel.account_code LIKE '6%' OR jel.account_code LIKE '8%')")
            ->select(
                DB::raw('EXTRACT(MONTH FROM je.entry_date)::int as month'),
                'jel.account_code',
                'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr')
            )
            ->groupBy('month', 'jel.account_code', 'ac.normal_balance')
            ->get()
            ->groupBy('month');

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthRows = $rows->get($m, collect());
            $mBal = [];
            foreach ($monthRows as $r) {
                $mBal[$r->account_code] = $r->normal_balance === 'debit'
                    ? (float) $r->dr - (float) $r->cr
                    : (float) $r->cr - (float) $r->dr;
            }
            $mb = function(string $prefix) use ($mBal) {
                $total = 0.0;
                foreach ($mBal as $code => $balance) {
                    if (str_starts_with((string) $code, $prefix)) {
                        $total += $balance;
                    }
                }
                return $total;
            };
            $mRevenue   = $mb('511') + $mb('512');
            $mCogs      = $mb('632');
            $mSelling   = $mb('641');
            $mAdmin     = $mb('642');
            $mCost      = $mCogs + $mSelling + $mAdmin;

            $monthly[] = [
                'month'        => $m,
                'revenue'      => $mRevenue,
                'cogs'         => $mCogs + $mSelling + $mAdmin,
                'gross_profit' => $mRevenue - $mCost,
            ];
        }

        return $monthly;
    }

    private function sumPrefix(array $balances, string $prefix): float
    {
        $total = 0.0;
        foreach ($balances as $code => $balance) {
            if (str_starts_with((string) $code, $prefix)) {
                $total += $balance;
            }
        }
        return $total;
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new IncomeStatementExport($request->all()),
            'income-statement-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
