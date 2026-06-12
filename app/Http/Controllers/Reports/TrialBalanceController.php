<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrialBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $mode     = in_array($request->input('mode'), ['raw', 'adjusted']) ? $request->input('mode') : 'adjusted';

        $directRows = $this->buildAccounts($dateFrom, $dateTo);

        // Totals always from ALL direct balances — Dr=Cr guaranteed by JE construction
        $totals = [
            'opening_debit'  => array_sum(array_column($directRows, 'openingDebit')),
            'opening_credit' => array_sum(array_column($directRows, 'openingCredit')),
            'debit'          => array_sum(array_column($directRows, 'dr')),
            'credit'         => array_sum(array_column($directRows, 'cr')),
            'closing_debit'  => array_sum(array_column($directRows, 'closingDebit')),
            'closing_credit' => array_sum(array_column($directRows, 'closingCredit')),
        ];

        // hiddenCount = số TK tổng hợp có ghi nợ/có trực tiếp (legacy/sai nguyên tắc)
        $hiddenCount = count(array_filter($directRows, fn ($r) => !$r['is_detail']));

        // adjusted: TK cha hiển thị roll-up từ TK con; raw: direct postings as-is
        $accounts = $mode === 'adjusted'
            ? $this->buildAdjustedView($directRows)
            : $directRows;

        return Inertia::render('Reports/TrialBalance/Index', [
            'accounts'    => $accounts,
            'totals'      => $totals,
            'hiddenCount' => $hiddenCount,
            'filters'     => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'mode' => $mode],
            'currentYear' => $year,
        ]);
    }

    /**
     * Adjusted view: TK cha hiển thị roll-up từ TK con (không tính direct postings vào TK cha).
     * TK con hiển thị direct balance như thường.
     * TK cha không có direct postings nhưng có con → vẫn xuất hiện với roll-up.
     */
    private function buildAdjustedView(array $directRows): array
    {
        $hierarchy = DB::table('account_codes')
            ->select('code', 'name', 'parent_code', 'level', 'is_detail', 'type')
            ->get()->keyBy('code');

        // Seed roll-up từ TK chi tiết (is_detail=true) có direct balance
        $rollNet = [];
        $rollDr  = [];
        $rollCr  = [];

        foreach ($directRows as $row) {
            if ($row['is_detail']) {
                $c = $row['code'];
                $rollNet[$c] = $row['openingDebit'] - $row['openingCredit'];
                $rollDr[$c]  = $row['dr'];
                $rollCr[$c]  = $row['cr'];
            }
        }

        // Bottom-up: từ sâu nhất → TK cha
        foreach ($hierarchy->sortByDesc('level') as $code => $acc) {
            if (!$acc->parent_code || !array_key_exists($code, $rollNet)) continue;
            $p = $acc->parent_code;
            $rollNet[$p] = ($rollNet[$p] ?? 0.0) + $rollNet[$code];
            $rollDr[$p]  = ($rollDr[$p]  ?? 0.0) + $rollDr[$code];
            $rollCr[$p]  = ($rollCr[$p]  ?? 0.0) + $rollCr[$code];
        }

        $result = [];

        // TK chi tiết: giữ nguyên direct balance
        foreach ($directRows as $row) {
            if ($row['is_detail']) {
                $result[] = $row;
            }
            // TK tổng hợp có direct postings: BỎ QUA (replaced by roll-up row below)
        }

        // TK cha: hiển thị roll-up (chỉ từ con, không tính direct posting vào cha)
        foreach ($rollNet as $code => $openNet) {
            $acc = $hierarchy->get($code);
            if (!$acc || $acc->is_detail) continue;

            $dr = $rollDr[$code] ?? 0.0;
            $cr = $rollCr[$code] ?? 0.0;
            $openingDebit  = max(0.0, (float) $openNet);
            $openingCredit = max(0.0, -(float) $openNet);
            $closingNet    = $openNet + $dr - $cr;
            $closingDebit  = max(0.0, $closingNet);
            $closingCredit = max(0.0, -$closingNet);

            if ($openingDebit == 0 && $openingCredit == 0 && $dr == 0 && $cr == 0) continue;

            $result[] = [
                'code'          => $code,
                'name'          => $acc->name,
                'level'         => $acc->level,
                'type'          => $acc->type ?? '',
                'is_detail'     => false,
                'is_rollup'     => true,
                'openingDebit'  => $openingDebit,
                'openingCredit' => $openingCredit,
                'dr'            => $dr,
                'cr'            => $cr,
                'closingDebit'  => $closingDebit,
                'closingCredit' => $closingCredit,
            ];
        }

        usort($result, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $result;
    }

    private function buildAccounts(string $from, string $to): array
    {
        // Opening: bút toán TRƯỚC kỳ (exclude_from_period_movement=false)
        //        + bút toán đầu kỳ (exclude_from_period_movement=true) dù ngày nào cũng vào đây
        $opening = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where(function ($q) use ($from) {
                $q->where(function ($q2) use ($from) {
                    // Bút toán thông thường trước kỳ
                    $q2->where('je.entry_date', '<', $from)
                       ->where('je.exclude_from_period_movement', false);
                })->orWhere('je.exclude_from_period_movement', true); // Mọi bút toán đầu kỳ
            })
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        // Period: bút toán thực tế TRONG kỳ — KHÔNG bao gồm bút toán đầu kỳ
        $period = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('je.exclude_from_period_movement', false)
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $allCodes = $opening->keys()->merge($period->keys())->unique()->sort()->values();

        $accountInfo = DB::table('account_codes')
            ->whereIn('code', $allCodes)
            ->select('code', 'name', 'normal_balance', 'type', 'level', 'is_detail')
            ->get()->keyBy('code');

        $result = [];
        foreach ($allCodes as $code) {
            $acc    = $accountInfo->get($code);
            $openDr = (float) ($opening->get($code)?->total_debit ?? 0);
            $openCr = (float) ($opening->get($code)?->total_credit ?? 0);
            $dr     = (float) ($period->get($code)?->total_debit ?? 0);
            $cr     = (float) ($period->get($code)?->total_credit ?? 0);

            $openingNet = $openDr - $openCr;

            // Quy tắc: net > 0 → Dư Nợ; net < 0 → Dư Có (không phụ thuộc normal_balance)
            // normal_balance không ảnh hưởng tới cột trình bày — chỉ để xác định "bình thường là dư bên nào"
            $openingDebit  = max(0.0, $openingNet);
            $openingCredit = max(0.0, -$openingNet);

            $closingNet    = $openingNet + $dr - $cr;
            $closingDebit  = max(0.0, $closingNet);
            $closingCredit = max(0.0, -$closingNet);

            $result[] = [
                'code'          => $code,
                'name'          => $acc?->name ?? '—',
                'level'         => $acc?->level ?? 1,
                'type'          => $acc?->type ?? '',
                'is_detail'     => (bool) ($acc?->is_detail ?? true),
                'is_rollup'     => false,
                'openingDebit'  => $openingDebit,
                'openingCredit' => $openingCredit,
                'dr'            => $dr,
                'cr'            => $cr,
                'closingDebit'  => $closingDebit,
                'closingCredit' => $closingCredit,
            ];
        }

        return $result;
    }

    public function export(Request $request): BinaryFileResponse
    {
        $suffix = $request->input('mode', 'adjusted') === 'raw' ? '-raw' : '';
        return Excel::download(
            new TrialBalanceExport($request->all()),
            'trial-balance-' . $request->input('year', now()->year) . $suffix . '.xlsx'
        );
    }
}
