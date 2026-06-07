<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\BalanceSheetExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BalanceSheetController extends Controller
{
    public function index(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());
        ['balanceSheet' => $balanceSheet, 'summary' => $summary] = $this->computeData($asOf);

        return Inertia::render('Reports/BalanceSheet/Index', [
            'balanceSheet' => $balanceSheet,
            'summary'      => $summary,
            'filters'      => ['as_of' => $asOf],
        ]);
    }

    /** Tính toán toàn bộ Balance Sheet — tách riêng để unit-testable */
    public function computeData(string $asOf): array
    {
        $bal = $this->accountBalancesAsOf($asOf);
        $b   = fn(string $code) => $this->sumPrefix($bal, $code);

        // ─── TÀI SẢN ──────────────────────────────────────────────────────────
        $cashOnHand  = $b('111');
        $bankBalance = $b('112');
        $ar          = $b('131');
        $prepaidST   = $b('142');
        $inventory   = $b('156') + $b('155') + $b('152') + $b('153');
        $faGross     = $b('211') + $b('213');
        $faAccDep    = $b('214');
        $faNet       = max(0.0, $faGross - $faAccDep);
        $prepaidLT   = $b('242');

        $cash                  = $cashOnHand + $bankBalance;
        $totalCurrentAssets    = $cash + $ar + $prepaidST + $inventory;
        $totalNonCurrentAssets = $faNet + $prepaidLT;
        $totalAssets           = $totalCurrentAssets + $totalNonCurrentAssets;

        // ─── NỢ PHẢI TRẢ ──────────────────────────────────────────────────────
        $ap            = $b('331');
        $vatPayable    = $b('3331') + $b('3332') + $b('3333');
        $citPayable    = $b('3334');
        $pitPayable    = $b('3335');
        $bhxhPayable   = $b('3383') + $b('3384') + $b('3385') + $b('3389');
        $salaryPayable = $b('334');
        $totalLiabilities = $ap + $vatPayable + $citPayable + $pitPayable + $bhxhPayable + $salaryPayable;

        // ─── VỐN CHỦ SỞ HỮU ──────────────────────────────────────────────────
        $charterCapital = $b('411');

        // TK 421*: số dư từ closing entries, phân phối lợi nhuận, điều chỉnh hồi tố
        $account421       = $this->sumPrefix($bal, '421');  // tổng 421 + 4211 + 4212 + TK con
        $balance4211      = $this->sumPrefix($bal, '4211'); // LNST năm trước
        $balance4212      = $this->sumPrefix($bal, '4212'); // LNST năm nay (sau closing)

        // P&L chưa kết chuyển: ≈ 0 nếu closing entries đã chạy và zero hóa 5xx/6xx/8xx
        $revenue          = $this->sumPrefix($bal, '5');
        $expenses         = $this->sumPrefix($bal, '6') + $this->sumPrefix($bal, '8');
        $currentNetIncome = $revenue - $expenses;

        // Case B: TK 421* (posted) + P&L chưa kết chuyển
        // Closing entries standard zero 5xx/6xx/8xx → không double-count
        $retainedEarnings = $account421 + $currentNetIncome;

        $totalEquity     = $charterCapital + $retainedEarnings;
        $totalLiabEquity = $totalLiabilities + $totalEquity;

        $balanceSheet = [
            ['label' => 'A. TÀI SẢN NGẮN HẠN',                                   'amount' => $totalCurrentAssets,    'bold' => true,  'indent' => 0, 'side' => 'asset'],
            ['label' => 'I. Tiền và tương đương tiền',                              'amount' => $cash,                  'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => '   - Tiền mặt (TK 111)',                                   'amount' => $cashOnHand,            'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => '   - Tiền gửi ngân hàng (TK 112)',                         'amount' => $bankBalance,           'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => 'II. Phải thu ngắn hạn – KH (TK 131)',                    'amount' => $ar,                    'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'III. Hàng tồn kho (TK 152/153/155/156)',                 'amount' => $inventory,             'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'IV. Chi phí trả trước ngắn hạn (TK 142)',               'amount' => $prepaidST,             'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'B. TÀI SẢN DÀI HẠN',                                    'amount' => $totalNonCurrentAssets, 'bold' => true,  'indent' => 0, 'side' => 'asset'],
            ['label' => 'I. TSCĐ hữu hình – Nguyên giá (TK 211)',                'amount' => $faGross,               'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => '   Hao mòn lũy kế (TK 214)',                            'amount' => -$faAccDep,             'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => '   Giá trị còn lại',                                    'amount' => $faNet,                 'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => 'II. Chi phí trả trước dài hạn (TK 242)',                'amount' => $prepaidLT,             'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'TỔNG CỘNG TÀI SẢN (A+B)',                               'amount' => $totalAssets,           'bold' => true,  'indent' => 0, 'side' => 'total_asset'],

            ['label' => 'A. NỢ PHẢI TRẢ',                                         'amount' => $totalLiabilities,      'bold' => true,  'indent' => 0, 'side' => 'liability'],
            ['label' => 'I. Phải trả người bán (TK 331)',                          'amount' => $ap,                    'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => 'II. Thuế và các khoản phải nộp',                         'amount' => $vatPayable + $citPayable + $pitPayable, 'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => '   - Thuế GTGT phải nộp (TK 3331)',                      'amount' => $vatPayable,            'bold' => false, 'indent' => 2, 'side' => 'liability'],
            ['label' => '   - Thuế TNDN (TK 3334)',                               'amount' => $citPayable,            'bold' => false, 'indent' => 2, 'side' => 'liability'],
            ['label' => '   - Thuế TNCN (TK 3335)',                               'amount' => $pitPayable,            'bold' => false, 'indent' => 2, 'side' => 'liability'],
            ['label' => 'III. Phải trả NLĐ (TK 334)',                             'amount' => $salaryPayable,         'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => 'IV. BHXH/BHYT/BHTN (TK 338)',                            'amount' => $bhxhPayable,           'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => 'B. VỐN CHỦ SỞ HỮU',                                      'amount' => $totalEquity,           'bold' => true,  'indent' => 0, 'side' => 'equity'],
            ['label' => 'Vốn đầu tư của CSH (TK 411)',                             'amount' => $charterCapital,        'bold' => false, 'indent' => 1, 'side' => 'equity'],
            ['label' => 'Lợi nhuận chưa phân phối (TK 421)',                      'amount' => $retainedEarnings,      'bold' => false, 'indent' => 1, 'side' => 'equity'],
            ['label' => '   - LNST năm trước (TK 4211)',                          'amount' => $balance4211,           'bold' => false, 'indent' => 2, 'side' => 'equity'],
            ['label' => '   - LNST năm nay (TK 4212)',                            'amount' => $balance4212,           'bold' => false, 'indent' => 2, 'side' => 'equity'],
            ['label' => '   - Lãi/(lỗ) chưa kết chuyển',                         'amount' => $currentNetIncome,      'bold' => false, 'indent' => 2, 'side' => 'equity'],
            ['label' => 'TỔNG CỘNG NGUỒN VỐN (A+B)',                              'amount' => $totalLiabEquity,       'bold' => true,  'indent' => 0, 'side' => 'total_equity'],
        ];

        return [
            'balanceSheet' => $balanceSheet,
            'summary'      => [
                'total_assets'             => $totalAssets,
                'total_liabilities'        => $totalLiabilities,
                'total_equity'             => $totalEquity,
                'total_liabilities_equity' => $totalLiabEquity,
                'balanced'                 => abs($totalAssets - $totalLiabEquity) < 1,
            ],
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Trả về map [account_code => net_balance] cho tất cả TK có phát sinh tính đến $asOf */
    private function accountBalancesAsOf(string $asOf): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOf)
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

    /** Tổng số dư của tất cả TK bắt đầu bằng $prefix */
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
            new BalanceSheetExport($request->all()),
            'balance-sheet-' . $request->input('as_of', now()->toDateString()) . '.xlsx'
        );
    }
}
