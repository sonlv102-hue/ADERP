<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\GeneralJournalDetailExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneralJournalDetailController extends Controller
{
    public function index(Request $request): Response
    {
        $year        = (int) $request->input('year', now()->year);
        $dateFrom    = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo      = $request->input('date_to')   ?: "{$year}-12-31";
        $accountCode = $request->input('account_code');
        $perPage     = 50;
        $page        = max(1, (int) $request->input('page', 1));

        $rows = $this->buildRows($dateFrom, $dateTo, $accountCode);

        $totalDebit  = array_sum(array_column($rows, 'debit'));
        $totalCredit = array_sum(array_column($rows, 'credit'));

        $total  = count($rows);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($rows, $offset, $perPage);

        $accounts = DB::table('account_codes')
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('name', 'code');

        return Inertia::render('Reports/GeneralJournalDetail/Index', [
            'accounts'      => $accounts,
            'rows'          => array_values($paged),
            'total'         => $total,
            'totalEntries'  => count(array_unique(array_column($rows, 'journal_entry_id'))),
            'totalDebit'    => $totalDebit,
            'totalCredit'   => $totalCredit,
            'difference'    => round($totalDebit - $totalCredit, 2),
            'currentPage'   => $page,
            'lastPage'      => (int) ceil(max(1, $total) / $perPage),
            'filters'       => [
                'year'         => $year,
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'account_code' => $accountCode,
            ],
            'currentYear'   => $year,
        ]);
    }

    private function buildRows(string $from, string $to, ?string $accountCode): array
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->leftJoin('account_codes as coa', 'coa.code', '=', 'jel.account_code')
            ->leftJoin('projects as prj', 'prj.id', '=', 'jel.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'je.created_by')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to]);

        if ($accountCode) {
            $query->where('jel.account_code', $accountCode);
        }

        $lines = $query
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jel.sort_order')
            ->select([
                'je.id as journal_entry_id',
                'je.code as ref',
                'je.entry_date as date',
                'je.description as entry_description',
                'je.source_type',
                'je.status',
                'je.posted_at',
                'u.name as created_by_name',
                'jel.account_code',
                'coa.name as account_name',
                'jel.description as line_description',
                'jel.debit',
                'jel.credit',
                'jel.partner_type',
                'jel.partner_id',
                'jel.project_id',
                'prj.name as project_name',
                'prj.code as project_code',
            ])
            ->get();

        $partnerNames = $this->resolvePartnerNames($lines);

        $result = [];
        $seq    = 1;

        foreach ($lines as $line) {
            $partnerName = ($line->partner_type && $line->partner_id)
                ? ($partnerNames[$line->partner_type][(int) $line->partner_id] ?? null)
                : null;

            $result[] = [
                'seq'                => $seq++,
                'journal_entry_id'   => $line->journal_entry_id,
                'date'               => $line->date,
                'ref'                => $line->ref,
                'entry_description'  => $line->entry_description,
                'account_code'       => $line->account_code,
                'account_name'       => $line->account_name ?? '',
                'line_description'   => $line->line_description ?: $line->entry_description,
                'debit'              => (float) $line->debit,
                'credit'             => (float) $line->credit,
                'partner_name'       => $partnerName ?? '',
                'project_name'       => $line->project_name
                    ? "{$line->project_code} - {$line->project_name}"
                    : '',
                'source_type'        => $line->source_type ?: '',
                'status'             => $line->status,
                'created_by_name'    => $line->created_by_name ?? '',
                'posted_at'          => $line->posted_at,
            ];
        }

        return $result;
    }

    private function resolvePartnerNames(Collection $lines): array
    {
        $supplierIds = $lines->where('partner_type', 'supplier')->pluck('partner_id')->filter()->unique();
        $customerIds = $lines->where('partner_type', 'customer')->pluck('partner_id')->filter()->unique();
        $employeeIds = $lines->where('partner_type', 'employee')->pluck('partner_id')->filter()->unique();

        return [
            'supplier' => $supplierIds->isNotEmpty()
                ? DB::table('suppliers')->whereIn('id', $supplierIds)->pluck('name', 'id')->toArray() : [],
            'customer' => $customerIds->isNotEmpty()
                ? DB::table('customers')->whereIn('id', $customerIds)->pluck('name', 'id')->toArray() : [],
            'employee' => $employeeIds->isNotEmpty()
                ? DB::table('employees')->whereIn('id', $employeeIds)->pluck('name', 'id')->toArray() : [],
        ];
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new GeneralJournalDetailExport($request->all()),
            'general-journal-detail-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
