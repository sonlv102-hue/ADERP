<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApDetailController extends Controller
{
    public function index(Request $request): Response
    {
        $supplierId = $request->input('supplier_id');
        $from       = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to         = $request->input('date_to',   now()->toDateString());

        $suppliers = DB::table('suppliers')->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'code']);

        $rows        = [];
        $openingBal  = 0;
        $closingBal  = 0;
        $totalDebit  = 0;
        $totalCredit = 0;

        if ($supplierId) {
            $openingBal = $this->balanceFor331($supplierId, null, $from, exclude: true);

            $lines = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->where('je.status', 'posted')
                ->where('jel.account_code', 'like', '331%')
                ->where('jel.partner_type', 'supplier')
                ->where('jel.partner_id', $supplierId)
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
                // TK 331 is credit-normal: credit increases payable, debit decreases it
                $running += (float)$line->credit - (float)$line->debit;
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

        return Inertia::render('Reports/ApDetail', [
            'suppliers'    => $suppliers,
            'rows'         => $rows,
            'opening_bal'  => $openingBal,
            'closing_bal'  => $closingBal,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'filters'      => $request->only(['supplier_id', 'date_from', 'date_to']),
        ]);
    }

    private function balanceFor331(int $supplierId, ?string $from, ?string $to, bool $exclude = false): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.account_code', 'like', '331%')
            ->where('jel.partner_type', 'supplier')
            ->where('jel.partner_id', $supplierId);

        if ($exclude && $from) {
            $query->where('je.entry_date', '<', $from);
        } elseif ($from && $to) {
            $query->whereBetween('je.entry_date', [$from, $to]);
        }

        $row = $query->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')->first();

        // TK 331 credit-normal: balance = cr - dr
        return (float)($row->cr ?? 0) - (float)($row->dr ?? 0);
    }
}
