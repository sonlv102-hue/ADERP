<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\ApDetailExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApDetailController extends Controller
{
    public function index(Request $request): Response
    {
        $supplierId = $request->input('supplier_id');
        $from       = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to         = $request->input('date_to',   now()->toDateString());

        $suppliers = DB::table('suppliers')->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'code']);

        $rows                = [];
        $openingBal331       = 0;
        $openingBal331ut     = 0;
        $openingBalNet       = 0;
        
        $totalDebit331       = 0;
        $totalCredit331      = 0;
        $totalDebit331ut     = 0;
        $totalCredit331ut    = 0;
        
        $closingBal331       = 0;
        $closingBal331ut     = 0;
        $closingBalNet       = 0;

        if ($supplierId) {
            // Tính số dư đầu kỳ
            $op331 = $this->balanceForAccount((int)$supplierId, '331', $from, null, true);
            $openingBal331 = $op331['credit'] - $op331['debit']; // 331 thường dư Có

            $op331ut = $this->balanceForAccount((int)$supplierId, '331UT', $from, null, true);
            $openingBal331ut = $op331ut['debit'] - $op331ut['credit']; // 331UT dư Nợ
            
            $openingBalNet = $openingBal331 - $openingBal331ut;

            // Tính phát sinh trong kỳ
            $in331 = $this->balanceForAccount((int)$supplierId, '331', $from, $to);
            $totalDebit331 = $in331['debit'];
            $totalCredit331 = $in331['credit'];

            $in331ut = $this->balanceForAccount((int)$supplierId, '331UT', $from, $to);
            $totalDebit331ut = $in331ut['debit'];
            $totalCredit331ut = $in331ut['credit'];

            // Tính số dư cuối kỳ
            $closingBal331 = $openingBal331 + $totalCredit331 - $totalDebit331;
            $closingBal331ut = $openingBal331ut + $totalDebit331ut - $totalCredit331ut;
            $closingBalNet = $closingBal331 - $closingBal331ut;

            // Lấy danh sách giao dịch
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
                    'jel.account_code',
                    DB::raw("COALESCE(jel.description, je.description, '') as description"),
                    'jel.debit',
                    'jel.credit',
                ])
                ->get();

            $running331 = $openingBal331;
            $running331ut = $openingBal331ut;
            foreach ($lines as $line) {
                if (str_starts_with($line->account_code, '331UT')) {
                    $running331ut += (float)$line->debit - (float)$line->credit;
                } else {
                    $running331 += (float)$line->credit - (float)$line->debit;
                }
                
                $rows[] = [
                    'date'         => $line->date,
                    'ref'          => $line->ref,
                    'account_code' => $line->account_code,
                    'description'  => $line->description,
                    'debit'        => (float)$line->debit,
                    'credit'       => (float)$line->credit,
                    'balance_331'   => $running331,
                    'balance_331ut' => $running331ut,
                    'balance_net'   => $running331 - $running331ut,
                ];
            }
        }

        return Inertia::render('Reports/ApDetail', [
            'suppliers'         => $suppliers,
            'rows'              => $rows,
            
            'opening_bal_331'   => $openingBal331,
            'opening_bal_331ut' => $openingBal331ut,
            'opening_bal_net'   => $openingBalNet,
            
            'total_debit_331'   => $totalDebit331,
            'total_credit_331'  => $totalCredit331,
            'total_debit_331ut' => $totalDebit331ut,
            'total_credit_331ut'=> $totalCredit331ut,
            
            'closing_bal_331'   => $closingBal331,
            'closing_bal_331ut' => $closingBal331ut,
            'closing_bal_net'   => $closingBalNet,
            
            'filters'           => $request->only(['supplier_id', 'date_from', 'date_to']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $supplierId = $request->input('supplier_id', 'all');
        return Excel::download(
            new ApDetailExport($request->all()),
            "ap-detail-tk331-{$supplierId}.xlsx"
        );
    }

    private function balanceForAccount(int $supplierId, string $accountPrefix, ?string $from, ?string $to, bool $exclude = false): array
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.partner_type', 'supplier')
            ->where('jel.partner_id', $supplierId);

        if ($accountPrefix === '331UT') {
            $query->where('jel.account_code', 'like', '331UT%');
        } else {
            $query->where('jel.account_code', 'like', '331%')
                ->where('jel.account_code', 'not like', '331UT%');
        }

        if ($exclude && $from) {
            $query->where('je.entry_date', '<', $from);
        } elseif ($from && $to) {
            $query->whereBetween('je.entry_date', [$from, $to]);
        }

        $row = $query->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')->first();
        return [
            'debit'  => (float)($row->dr ?? 0),
            'credit' => (float)($row->cr ?? 0),
        ];
    }
}
