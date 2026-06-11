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
            ->select('code', 'name', 'normal_balance', 'type', 'level')
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
