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

        // Doanh thu thuần (TK 511 — phát sinh Có trong kỳ, lấy từ GL đã posted)
        $revenue      = $b('511');
        // Giảm trừ doanh thu: phát sinh Nợ TK 511 qua nghiệp vụ giảm giá/trả hàng
        // TK 521 là tùy chọn theo cấu hình riêng, không phải mặc định TT133 B02-DNN
        $salesReturn  = $b('521');
        $netRevenue   = $revenue - $salesReturn;

        // Giá vốn (TK 632 — thương mại: Nợ 632/Có 156; dự án: chỉ sau khi nghiệm thu Nợ 632/Có 154)
        $cogs         = $b('632');

        // Lợi nhuận gộp
        $grossProfit  = $netRevenue - $cogs;
        $grossMargin  = $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 1) : null;

        // Chi phí tài chính
        $financialIncome  = $b('515');
        $financialExpense = $b('635');

        // Chi phí quản lý kinh doanh (TK 642 tổng hợp cho công thức; 6421/6422 hiển thị chi tiết)
        // BUG FIX: $b('642') đã bao gồm 6421 + 6422 (str_starts_with). Chỉ trừ totalMgmtExpense MỘT lần.
        $sellingExpense   = $b('6421');  // Chi phí bán hàng — chỉ để hiển thị chi tiết
        $adminOnlyExpense = $b('6422');  // Chi phí QLDN — chỉ để hiển thị chi tiết
        $totalMgmtExpense = $b('642');   // Tổng 642* (6421 + 6422 + bất kỳ 642x nào) — dùng trong công thức

        $otherIncome      = $b('711');
        $otherExpense     = $b('811');

        // Công thức: trừ totalMgmtExpense một lần duy nhất (không trừ riêng sellingExpense + adminExpense)
        $netOpProfit    = $grossProfit + $financialIncome - $financialExpense - $totalMgmtExpense;
        $ebt            = $netOpProfit + $otherIncome - $otherExpense;
        $cit            = $b('821');  // Thuế TNDN (TK 821 — bao gồm 8211 hiện hành + 8212 hoãn lại)
        $netProfit      = $ebt - $cit;

        // Breakdown theo tháng — 1 query duy nhất
        $monthly = $this->monthlyBreakdown($year);

        // Drill-down data cho từng nhóm TK lớn trong kỳ
        $drillDown = $this->buildDrillDown($bal, $dateFrom, $dateTo);

        $statement = [
            ['label' => 'Doanh thu bán hàng và CCDV (TK 511)',       'amount' => $revenue,              'bold' => false, 'indent' => 0, 'code' => '01'],
            ['label' => '  Trong đó: TK 5111 — Thương mại',          'amount' => $b('5111'),            'bold' => false, 'indent' => 2, 'code' => ''],
            ['label' => '  Trong đó: TK 5113 — Dịch vụ/Dự án',      'amount' => $b('5113'),            'bold' => false, 'indent' => 2, 'code' => ''],
            ['label' => 'Các khoản giảm trừ doanh thu',              'amount' => -$salesReturn,         'bold' => false, 'indent' => 1, 'code' => '02'],
            ['label' => 'Doanh thu thuần (Mã 10 = 01 - 02)',         'amount' => $netRevenue,           'bold' => true,  'indent' => 0, 'code' => '10'],
            ['label' => 'Giá vốn hàng bán (TK 632)',                 'amount' => -$cogs,                'bold' => false, 'indent' => 1, 'code' => '11'],
            ['label' => 'Lợi nhuận gộp (Mã 20 = 10 - 11)',          'amount' => $grossProfit,          'bold' => true,  'indent' => 0, 'code' => '20'],
            ['label' => 'Doanh thu tài chính (TK 515)',              'amount' => $financialIncome,      'bold' => false, 'indent' => 1, 'code' => '21'],
            ['label' => 'Chi phí tài chính (TK 635)',                'amount' => -$financialExpense,    'bold' => false, 'indent' => 1, 'code' => '22'],
            ['label' => 'Chi phí quản lý kinh doanh (TK 642)',       'amount' => -$totalMgmtExpense,    'bold' => false, 'indent' => 1, 'code' => '24'],
            ['label' => '  Trong đó: Chi phí bán hàng (6421)',       'amount' => -$sellingExpense,      'bold' => false, 'indent' => 2, 'code' => ''],
            ['label' => '  Trong đó: Chi phí QLDN (6422)',           'amount' => -$adminOnlyExpense,    'bold' => false, 'indent' => 2, 'code' => ''],
            ['label' => 'Lợi nhuận thuần từ HĐKD (Mã 30)',          'amount' => $netOpProfit,          'bold' => true,  'indent' => 0, 'code' => '30'],
            ['label' => 'Thu nhập khác (TK 711)',                    'amount' => $otherIncome,          'bold' => false, 'indent' => 1, 'code' => '31'],
            ['label' => 'Chi phí khác (TK 811)',                     'amount' => -$otherExpense,        'bold' => false, 'indent' => 1, 'code' => '32'],
            ['label' => 'Lợi nhuận trước thuế (Mã 50)',              'amount' => $ebt,                  'bold' => true,  'indent' => 0, 'code' => '50'],
            ['label' => 'Thuế TNDN (TK 821)',                        'amount' => -$cit,                 'bold' => false, 'indent' => 1, 'code' => '51'],
            ['label' => 'Lợi nhuận sau thuế (Mã 60)',               'amount' => $netProfit,            'bold' => true,  'indent' => 0, 'code' => '60'],
        ];

        $summary = [
            'revenue'              => $revenue,
            'vat_out'              => 0,
            'total_cogs'           => $cogs,
            'gross_profit'         => $grossProfit,
            'gross_margin'         => $grossMargin,
            'net_profit'           => $netProfit,
            'net_op_profit'        => $netOpProfit,
            'ebt'                  => $ebt,
            'vat_in'               => 0,
            'purchase_total'       => 0,
            'total_mgmt_expense'   => $totalMgmtExpense,
            'selling_expense'      => $sellingExpense,
            'admin_only_expense'   => $adminOnlyExpense,
            'financial_expense'    => $financialExpense,
            'other_expense'        => $otherExpense,
        ];

        return Inertia::render('Reports/IncomeStatement/Index', [
            'statement'   => $statement,
            'monthly'     => $monthly,
            'summary'     => $summary,
            'warnings'    => $warnings,
            'drillDown'   => $drillDown,
            'filters'     => $request->only(['year', 'date_from', 'date_to']),
            'currentYear' => $year,
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
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
            $mRevenue      = $mb('511');
            $mCogs         = $mb('632');
            $mTotalMgmt    = $mb('642');  // bao gồm 6421 + 6422, không double-count
            $mTotalExpense = $mCogs + $mTotalMgmt;

            $monthly[] = [
                'month'        => $m,
                'revenue'      => $mRevenue,
                'cogs'         => $mTotalExpense,
                'gross_profit' => $mRevenue - $mTotalExpense,
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
                'level'      => 'error',
                'message'    => "Không có bút toán nào được posted trong kỳ {$from} – {$to}. "
                              . "Kiểm tra lại bộ lọc ngày hoặc trạng thái bút toán.",
                'draft_jes'  => [],
            ];
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
                'level'     => 'info',
                'message'   => 'Đã có bút toán kết chuyển (TK 911) trong kỳ. '
                             . 'Báo cáo tính theo tổng phát sinh thuần của TK doanh thu/chi phí — không bị ảnh hưởng bởi kết chuyển.',
                'draft_jes' => [],
            ];
        }

        // 3. Doanh thu TK 511 = 0 dù có bút toán
        $revenue = $this->sumPrefix($bal, '511');
        if ($revenue == 0) {
            $warnings[] = [
                'level'     => 'warning',
                'message'   => 'Doanh thu (TK 511) = 0 trong kỳ. '
                             . 'Kiểm tra: (1) hóa đơn bán hàng đã xác nhận/posted chưa; '
                             . '(2) bút toán doanh thu có hạch toán đúng TK 5111/5113 không; '
                             . '(3) entry_date của bút toán có nằm trong kỳ báo cáo không.',
                'draft_jes' => [],
            ];
        }

        // 4. Có doanh thu nhưng giá vốn = 0
        $cogs = $this->sumPrefix($bal, '632');
        if ($revenue > 0 && $cogs == 0) {
            $warnings[] = [
                'level'     => 'warning',
                'message'   => 'Doanh thu có nhưng giá vốn (TK 632) = 0. '
                             . 'Kiểm tra: phiếu xuất kho đã posted chưa; hoặc dự án chưa nghiệm thu (chi phí vẫn ở TK 154).',
                'draft_jes' => [],
            ];
        }

        // 5. Bút toán chưa posted trong kỳ — kèm danh sách chi tiết (tối đa 10)
        $draftCount = DB::table('journal_entries')
            ->whereIn('status', ['draft'])
            ->whereBetween('entry_date', [$from, $to])
            ->count();

        if ($draftCount > 0) {
            $draftJes = DB::table('journal_entries')
                ->whereIn('status', ['draft'])
                ->whereBetween('entry_date', [$from, $to])
                ->select('id', 'code', 'entry_date', 'description', 'reference_type')
                ->orderByDesc('entry_date')
                ->limit(10)
                ->get()
                ->map(fn($je) => [
                    'id'             => $je->id,
                    'code'           => $je->code,
                    'entry_date'     => $je->entry_date,
                    'description'    => $je->description,
                    'reference_type' => $je->reference_type,
                ])
                ->toArray();

            $warnings[] = [
                'level'      => 'warning',
                'message'    => "Có {$draftCount} bút toán chưa posted trong kỳ — số liệu KQHĐKD chưa đầy đủ. "
                              . 'Hóa đơn/chứng từ đã có nhưng chưa post GL sẽ không lên báo cáo.',
                'draft_jes'  => $draftJes,
                'draft_count' => $draftCount,
            ];
        }

        return $warnings;
    }

    /**
     * Drill-down: phát sinh Nợ/Có từng TK lớn trong kỳ để người dùng hiểu nguồn gốc số liệu.
     */
    private function buildDrillDown(array $bal, string $from, string $to): array
    {
        $groups = [
            '511'  => 'Doanh thu bán hàng và CCDV',
            '515'  => 'Doanh thu tài chính',
            '632'  => 'Giá vốn hàng bán',
            '635'  => 'Chi phí tài chính',
            '6421' => 'Chi phí bán hàng',
            '6422' => 'Chi phí QLDN',
            '711'  => 'Thu nhập khác',
            '811'  => 'Chi phí khác',
            '821'  => 'Thuế TNDN',
        ];

        $result = [];
        foreach ($groups as $prefix => $label) {
            $amount = $this->sumPrefix($bal, $prefix);
            if ($amount != 0) {
                $result[] = [
                    'tk'     => $prefix,
                    'label'  => $label,
                    'amount' => $amount,
                ];
            }
        }

        // Đếm số chứng từ nguồn trong kỳ chưa có bút toán posted
        $unpostedInvoices = DB::table('invoices')
            ->whereNotIn('status', ['draft'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotExists(function ($q) {
                $q->from('journal_entries')
                  ->where('reference_type', 'invoice')
                  ->whereColumn('reference_id', 'invoices.id')
                  ->where('status', 'posted');
            })
            ->count();

        return [
            'by_account'        => $result,
            'unposted_invoices' => $unpostedInvoices,
            'period'            => ['from' => $from, 'to' => $to],
        ];
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
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $priorYear = $year - 1;

        $currentBal = $this->periodBalances($dateFrom, $dateTo);
        $priorBal   = $this->periodBalances("{$priorYear}-01-01", "{$priorYear}-12-31");

        $current = $this->buildStatementFromBal($currentBal);
        $prior   = $this->buildStatementFromBal($priorBal);

        return Excel::download(
            new IncomeStatementExport($current, $prior, $dateFrom, $dateTo),
            "b02-dnn-{$year}.xlsx"
        );
    }

    private function buildStatementFromBal(array $bal): array
    {
        $b = fn(string $prefix) => $this->sumPrefix($bal, $prefix);

        $revenue          = $b('511');
        $salesReturn      = $b('521');
        $netRevenue       = $revenue - $salesReturn;
        $cogs             = $b('632');
        $grossProfit      = $netRevenue - $cogs;
        $financialIncome  = $b('515');
        $financialExpense = $b('635');
        $sellingExpense   = $b('6421');
        $adminOnlyExpense = $b('6422');
        $totalMgmtExpense = $b('642');
        $otherIncome      = $b('711');
        $otherExpense     = $b('811');
        $netOpProfit      = $grossProfit + $financialIncome - $financialExpense - $totalMgmtExpense;
        $ebt              = $netOpProfit + $otherIncome - $otherExpense;
        $cit              = $b('821');
        $netProfit        = $ebt - $cit;

        return [
            ['code' => '01', 'label' => 'Doanh thu bán hàng và CCDV',        'amount' => $revenue,           'bold' => false],
            ['code' => '',   'label' => '  TK 5111 — Thương mại',            'amount' => $b('5111'),         'bold' => false],
            ['code' => '',   'label' => '  TK 5113 — Dịch vụ/Dự án',        'amount' => $b('5113'),         'bold' => false],
            ['code' => '02', 'label' => 'Các khoản giảm trừ doanh thu',      'amount' => -$salesReturn,      'bold' => false],
            ['code' => '10', 'label' => 'Doanh thu thuần (10 = 01 - 02)',     'amount' => $netRevenue,        'bold' => true],
            ['code' => '11', 'label' => 'Giá vốn hàng bán',                  'amount' => -$cogs,             'bold' => false],
            ['code' => '20', 'label' => 'Lợi nhuận gộp (20 = 10 - 11)',      'amount' => $grossProfit,       'bold' => true],
            ['code' => '21', 'label' => 'Doanh thu tài chính',               'amount' => $financialIncome,   'bold' => false],
            ['code' => '22', 'label' => 'Chi phí tài chính',                 'amount' => -$financialExpense, 'bold' => false],
            ['code' => '24', 'label' => 'Chi phí quản lý kinh doanh',        'amount' => -$totalMgmtExpense, 'bold' => false],
            ['code' => '',   'label' => '  Chi phí bán hàng (6421)',         'amount' => -$sellingExpense,   'bold' => false],
            ['code' => '',   'label' => '  Chi phí QLDN (6422)',             'amount' => -$adminOnlyExpense, 'bold' => false],
            ['code' => '30', 'label' => 'Lợi nhuận thuần từ HĐKD (30)',      'amount' => $netOpProfit,       'bold' => true],
            ['code' => '31', 'label' => 'Thu nhập khác',                     'amount' => $otherIncome,       'bold' => false],
            ['code' => '32', 'label' => 'Chi phí khác',                      'amount' => -$otherExpense,     'bold' => false],
            ['code' => '50', 'label' => 'Lợi nhuận trước thuế (50)',         'amount' => $ebt,               'bold' => true],
            ['code' => '51', 'label' => 'Thuế TNDN',                         'amount' => -$cit,              'bold' => false],
            ['code' => '60', 'label' => 'Lợi nhuận sau thuế (60)',           'amount' => $netProfit,         'bold' => true],
        ];
    }
}
