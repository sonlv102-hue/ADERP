<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\AccountLedgerExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $account  = $request->input('account', '131');
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";

        // Tất cả tài khoản cho dropdown + phân giải cây cha→con
        $allAccountsRaw = DB::table('account_codes')
            ->where('is_active', true)
            ->orderBy('code')
            ->select('code', 'name', 'parent_code', 'normal_balance')
            ->get();

        $allAccounts = $allAccountsRaw->pluck('name', 'code');

        if (!$allAccounts->has($account)) {
            $account = $allAccounts->keys()->first() ?? '131';
        }

        $accountName   = $allAccounts->get($account, $account);
        $normalBalance = $allAccountsRaw->firstWhere('code', $account)?->normal_balance ?? 'debit';

        // Resolve TK cha → tất cả TK con (bao gồm chính nó)
        $accountCodes = $this->resolveDescendants($account, $allAccountsRaw);

        // Số dư đầu kỳ
        $openingData = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jel.account_code', $accountCodes)
            ->where('je.entry_date', '<', $dateFrom)
            ->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')
            ->first();

        $openDr         = (float) ($openingData?->dr ?? 0);
        $openCr         = (float) ($openingData?->cr ?? 0);
        $openingBalance = $normalBalance === 'debit' ? $openDr - $openCr : $openCr - $openDr;

        // Các dòng phát sinh trong kỳ
        $lineRows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jel.account_code', $accountCodes)
            ->whereBetween('je.entry_date', [$dateFrom, $dateTo])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jel.sort_order')
            ->select('je.id as journal_entry_id', 'je.entry_date as date', 'je.code as ref',
                'je.description as entry_desc', 'jel.description as line_desc',
                'jel.debit', 'jel.credit')
            ->get();

        $rows    = [];
        $balance = $openingBalance;

        foreach ($lineRows as $l) {
            $balance += $normalBalance === 'debit'
                ? ((float) $l->debit - (float) $l->credit)
                : ((float) $l->credit - (float) $l->debit);

            $rows[] = [
                'journal_entry_id' => $l->journal_entry_id,
                'date'             => $l->date,
                'ref'              => $l->ref,
                'description'      => $l->line_desc ?? $l->entry_desc,
                'debit'            => (float) $l->debit,
                'credit'           => (float) $l->credit,
                'balance'          => $balance,
            ];
        }

        $closingBalance = $balance;
        $totalDebit     = array_sum(array_column($rows, 'debit'));
        $totalCredit    = array_sum(array_column($rows, 'credit'));

        return Inertia::render('Reports/AccountLedger/Index', [
            'rows'           => $rows,
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
            'totalDebit'     => $totalDebit,
            'totalCredit'    => $totalCredit,
            'account'        => $account,
            'accountName'    => $accountName,
            'accounts'       => $allAccounts,
            'filters'        => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'account' => $account],
            'currentYear'    => $year,
        ]);
    }

    private function resolveDescendants(string $rootCode, \Illuminate\Support\Collection $allCodes): array
    {
        $result = [$rootCode];
        $queue  = [$rootCode];
        while (!empty($queue)) {
            $parent   = array_shift($queue);
            $children = $allCodes->where('parent_code', $parent)->pluck('code')->all();
            foreach ($children as $child) {
                if (!in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[]  = $child;
                }
            }
        }
        return $result;
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AccountLedgerExport($request->all()),
            'account-ledger-tk' . $request->input('account', '131') . '-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
