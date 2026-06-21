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

/**
 * S01-DNN — Sổ nhật ký chung.
 * Mỗi dòng journal_entry_line xuất thành 1 hàng riêng.
 * Ngày, Số CT chỉ hiển thị trên dòng đầu tiên của mỗi bút toán.
 */
class GeneralJournalExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'S01-DNN'; }

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
            ->select(
                'jel.journal_entry_id',
                'jel.account_code',
                'jel.description as line_desc',
                'jel.debit',
                'jel.credit'
            )
            ->get()
            ->groupBy('journal_entry_id');

        $result = [];
        $jeSeq  = 1;

        foreach ($journalEntries as $e) {
            $entryLines = $lines->get($e->id, collect());
            $isFirst    = true;

            foreach ($entryLines as $line) {
                $result[] = (object) [
                    'seq'         => $isFirst ? $jeSeq : '',
                    'date'        => $isFirst ? $e->entry_date : '',
                    'ref'         => $isFirst ? $e->code : '',
                    'description' => $line->line_desc ?: $e->description,
                    'account'     => $line->account_code,
                    'debit'       => (float) $line->debit,
                    'credit'      => (float) $line->credit,
                ];
                $isFirst = false;
            }

            $jeSeq++;
        }

        return collect($result);
    }

    public function headings(): array
    {
        return ['STT', 'Ngày', 'Số CT', 'Diễn giải', 'TK', 'Nợ (VND)', 'Có (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->seq,
            $row->date,
            $row->ref,
            $row->description,
            $row->account,
            $row->debit  > 0 ? $row->debit  : '',
            $row->credit > 0 ? $row->credit : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(50);
        $sheet->getColumnDimension('E')->setWidth(10);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getStyle("F2:G{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }
}
