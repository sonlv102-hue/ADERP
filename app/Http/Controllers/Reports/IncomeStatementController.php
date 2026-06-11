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

        $bal      = $this->periodBalances($dateFrom, $dateTo);
        $b        = fn(string $prefix) => $this->sumPrefix($bal, $prefix);
        $warnings = $this->detectWarnings($dateFrom, $dateTo, $bal);

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
            'warnings'    => $warnings,
            'filters'     => $request->only(['year', 'date_from', 'date_to']),
            'currentYear' => $year,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Tổng phát sinh một chiều trong kỳ [account_code => amount].
     * Dùng SUM(credit) cho TK doanh thu (credit-normal),
     *      SUM(debit)  cho TK chi phí (debit-normal).
     * Cách này miễn nhiễm với kết chuyển cuối kỳ (Dr 511/Cr 911, Dr 911/Cr 632…)
     * vì kết chuyển chỉ tác động đến chiều ngược lại.
     */
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
            // One-sided: expense TK → debit total; revenue TK → credit total
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr
                : (float) $r->cr;
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
                    ? (float) $r->dr
                    : (float) $r->cr;
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

    private function detectWarnings(string $from, string $to, array $bal): array
    {
        $warnings = [];

        // 1. Không có bút toán posted nào trong kỳ
        $totalPosted = DB::table('journal_entries')
            ->where('status', 'posted')
            ->whereBetween('entry_date', [$from, $to])
            ->count();

        if ($totalPosted === 0) {
            $warnings[] = [
                'level'   => 'error',
                'message' => "Không có bút toán nào được posted trong kỳ {$from} – {$to}. "
                           . "Kiểm tra lại bộ lọc ngày hoặc trạng thái bút toán.",
            ];
            // Không cần check thêm
            return $warnings;
        }

        // 2. Có bút toán kết chuyển TK 911
        $has911 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', '911')
            ->exists();

        if ($has911) {
            $warnings[] = [
                'level'   => 'info',
                'message' => 'Đã có bút toán kết chuyển (TK 911) trong kỳ. '
                           . 'Báo cáo tính theo tổng phát sinh Có của TK doanh thu và tổng phát sinh Nợ của TK chi phí — '
                           . 'không bị ảnh hưởng bởi kết chuyển.',
            ];
        }

        // 3. Doanh thu = 0 dù có bút toán
        $revenue = $this->sumPrefix($bal, '511') + $this->sumPrefix($bal, '512');
        if ($revenue == 0) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'Doanh thu (TK 511/512) = 0 trong kỳ. '
                           . 'Kiểm tra: (1) hóa đơn bán hàng đã được xác nhận/posted chưa; '
                           . '(2) doanh thu có được hạch toán vào TK 511x không.',
            ];
        }

        // 4. Có doanh thu nhưng giá vốn = 0
        $cogs = $this->sumPrefix($bal, '632');
        if ($revenue > 0 && $cogs == 0) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => 'Doanh thu có nhưng giá vốn (TK 632) = 0. '
                           . 'Kiểm tra: phiếu xuất kho đã được posted chưa.',
            ];
        }

        // 5. Bút toán chưa posted trong kỳ
        $draftCount = DB::table('journal_entries')
            ->whereIn('status', ['draft', 'pending'])
            ->whereBetween('entry_date', [$from, $to])
            ->count();

        if ($draftCount > 0) {
            $warnings[] = [
                'level'   => 'warning',
                'message' => "Có {$draftCount} bút toán chưa được posted trong kỳ — "
                           . 'số liệu chưa đầy đủ. Kiểm tra và post các bút toán còn draft.',
            ];
        }

        return $warnings;
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
