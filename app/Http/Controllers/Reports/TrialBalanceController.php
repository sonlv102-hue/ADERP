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

        $accounts = $this->buildAccounts($dateFrom, $dateTo);

        $totals = [
            'opening_debit'  => array_sum(array_column($accounts, 'openingDebit')),
            'opening_credit' => array_sum(array_column($accounts, 'openingCredit')),
            'debit'          => array_sum(array_column($accounts, 'dr')),
            'credit'         => array_sum(array_column($accounts, 'cr')),
            'closing_debit'  => array_sum(array_column($accounts, 'closingDebit')),
            'closing_credit' => array_sum(array_column($accounts, 'closingCredit')),
        ];

        return Inertia::render('Reports/TrialBalance/Index', [
            'accounts'    => $accounts,
            'totals'      => $totals,
            'filters'     => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'currentYear' => $year,
        ]);
    }

    private function buildAccounts(string $from, string $to): array
    {
        // Opening: tất cả bút toán TRƯỚC kỳ
        $opening = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<', $from)
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        // Period: bút toán TRONG kỳ
        $period = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $allCodes = $opening->keys()->merge($period->keys())->unique()->sort()->values();

        $accountInfo = DB::table('account_codes')
            ->whereIn('code', $allCodes)
            ->select('code', 'name', 'normal_balance', 'type', 'level')
            ->get()->keyBy('code');

        $result = [];
        foreach ($allCodes as $code) {
            $acc    = $accountInfo->get($code);
            $openDr = (float) ($opening->get($code)?->total_debit ?? 0);
            $openCr = (float) ($opening->get($code)?->total_credit ?? 0);
            $dr     = (float) ($period->get($code)?->total_debit ?? 0);
            $cr     = (float) ($period->get($code)?->total_credit ?? 0);

            $normalBalance = $acc?->normal_balance ?? 'debit';
            $openingNet    = $openDr - $openCr;

            if ($normalBalance === 'debit') {
                $openingDebit  = max(0.0, $openingNet);
                $openingCredit = max(0.0, -$openingNet);
            } else {
                $openingDebit  = max(0.0, -$openingNet);
                $openingCredit = max(0.0, $openingNet);
            }

            $closingNet = $openingNet + $dr - $cr;
            if ($normalBalance === 'debit') {
                $closingDebit  = max(0.0, $closingNet);
                $closingCredit = max(0.0, -$closingNet);
            } else {
                $closingDebit  = max(0.0, -$closingNet);
                $closingCredit = max(0.0, $closingNet);
            }

            $result[] = [
                'code'          => $code,
                'name'          => $acc?->name ?? '—',
                'level'         => $acc?->level ?? 1,
                'type'          => $acc?->type ?? '',
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
        return Excel::download(
            new TrialBalanceExport($request->all()),
            'trial-balance-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
