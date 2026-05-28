<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\GeneralJournalExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneralJournalController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $perPage  = 50;
        $page     = max(1, (int) $request->input('page', 1));

        $entries = $this->buildEntries($dateFrom, $dateTo);

        $totalDebit  = array_sum(array_column($entries, 'amount'));
        $totalCredit = $totalDebit;

        $total  = count($entries);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($entries, $offset, $perPage);

        return Inertia::render('Reports/GeneralJournal/Index', [
            'entries'     => array_values($paged),
            'total'       => $total,
            'totalDebit'  => $totalDebit,
            'totalCredit' => $totalCredit,
            'currentPage' => $page,
            'lastPage'    => (int) ceil(max(1, $total) / $perPage),
            'filters'     => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'currentYear' => $year,
        ]);
    }

    private function buildEntries(string $from, string $to): array
    {
        // Lấy tất cả bút toán đã hạch toán trong kỳ
        $journalEntries = DB::table('journal_entries as je')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->select('je.id', 'je.code', 'je.entry_date', 'je.description')
            ->get();

        if ($journalEntries->isEmpty()) {
            return [];
        }

        $entryIds = $journalEntries->pluck('id');

        // Load tất cả lines một lần (tránh N+1)
        $lines = DB::table('journal_entry_lines as jel')
            ->whereIn('jel.journal_entry_id', $entryIds)
            ->orderBy('jel.journal_entry_id')
            ->orderBy('jel.sort_order')
            ->select('jel.journal_entry_id', 'jel.account_code', 'jel.debit', 'jel.credit')
            ->get()->groupBy('journal_entry_id');

        $result = [];
        $seq    = 1;

        foreach ($journalEntries as $e) {
            $entryLines  = $lines->get($e->id, collect());
            $debitCodes  = $entryLines->where('debit', '>', 0)->pluck('account_code')->join(' / ');
            $creditCodes = $entryLines->where('credit', '>', 0)->pluck('account_code')->join(' / ');
            $totalDebit  = (float) $entryLines->sum('debit');

            $result[] = [
                'seq'         => $seq++,
                'date'        => $e->entry_date,
                'ref'         => $e->code,
                'description' => $e->description,
                'partner'     => '',
                'debit_tk'    => $debitCodes ?: '—',
                'credit_tk'   => $creditCodes ?: '—',
                'amount'      => $totalDebit,
            ];
        }

        return $result;
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new GeneralJournalExport($request->all()),
            'general-journal-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
