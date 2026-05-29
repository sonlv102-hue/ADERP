<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GeneralJournalExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Nhật ký chung'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";

        $journalEntries = DB::table('journal_entries as je')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->select('je.id', 'je.code', 'je.entry_date', 'je.description')
            ->get();

        if ($journalEntries->isEmpty()) {
            return collect();
        }

        $entryIds = $journalEntries->pluck('id');

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

            $result[] = (object) [
                'seq'         => $seq++,
                'date'        => $e->entry_date,
                'ref'         => $e->code,
                'description' => $e->description,
                'debit_tk'    => $debitCodes ?: '—',
                'credit_tk'   => $creditCodes ?: '—',
                'amount'      => $totalDebit,
            ];
        }

        return collect($result);
    }

    public function headings(): array
    {
        return ['STT', 'Ngày', 'Số chứng từ', 'Diễn giải', 'TK Nợ', 'TK Có', 'Số tiền (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->seq,
            $row->date,
            $row->ref,
            $row->description,
            $row->debit_tk,
            $row->credit_tk,
            $row->amount,
        ];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
