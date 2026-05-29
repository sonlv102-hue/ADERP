<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\ArDetailExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArDetailController extends Controller
{
    public function index(Request $request): Response
    {
        $customerId = $request->input('customer_id');
        $from       = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to         = $request->input('date_to',   now()->toDateString());

        $customers = DB::table('customers')->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'code']);

        $rows        = [];
        $openingBal  = 0;
        $closingBal  = 0;
        $totalDebit  = 0;
        $totalCredit = 0;

        if ($customerId) {
            $customer = $customers->firstWhere('id', (int) $customerId);

            $openingBal = $this->balanceFor131($customerId, null, $from, exclude: true);

            $lines = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->where('je.status', 'posted')
                ->where('jel.account_code', 'like', '131%')
                ->where('jel.partner_type', 'customer')
                ->where('jel.partner_id', $customerId)
                ->whereBetween('je.entry_date', [$from, $to])
                ->orderBy('je.entry_date')
                ->orderBy('je.id')
                ->orderBy('jel.sort_order')
                ->select([
                    'je.entry_date as date',
                    'je.code as ref',
                    DB::raw("COALESCE(jel.description, je.description, '') as description"),
                    'jel.debit',
                    'jel.credit',
                ])
                ->get();

            $running = $openingBal;
            foreach ($lines as $line) {
                $running += (float)$line->debit - (float)$line->credit;
                $totalDebit  += (float)$line->debit;
                $totalCredit += (float)$line->credit;
                $rows[] = [
                    'date'        => $line->date,
                    'ref'         => $line->ref,
                    'description' => $line->description,
                    'debit'       => (float)$line->debit,
                    'credit'      => (float)$line->credit,
                    'balance'     => $running,
                ];
            }

            $closingBal = $running;
        }

        return Inertia::render('Reports/ArDetail', [
            'customers'    => $customers,
            'rows'         => $rows,
            'opening_bal'  => $openingBal,
            'closing_bal'  => $closingBal,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'filters'      => $request->only(['customer_id', 'date_from', 'date_to']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $customerId = $request->input('customer_id', 'all');
        return Excel::download(
            new ArDetailExport($request->all()),
            "ar-detail-tk131-{$customerId}.xlsx"
        );
    }

    private function balanceFor131(int $customerId, ?string $from, ?string $to, bool $exclude = false): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.account_code', 'like', '131%')
            ->where('jel.partner_type', 'customer')
            ->where('jel.partner_id', $customerId);

        if ($exclude && $from) {
            $query->where('je.entry_date', '<', $from);
        } elseif ($from && $to) {
            $query->whereBetween('je.entry_date', [$from, $to]);
        }

        $row = $query->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')->first();

        return (float)($row->dr ?? 0) - (float)($row->cr ?? 0);
    }
}
