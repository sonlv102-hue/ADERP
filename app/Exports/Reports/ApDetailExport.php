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

class ApDetailExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Sổ CT TK 331'; }

    public function collection(): Collection
    {
        $supplierId = $this->filters['supplier_id'] ?? null;
        $from       = $this->filters['date_from'] ?? now()->startOfMonth()->toDateString();
        $to         = $this->filters['date_to']   ?? now()->toDateString();

        if (!$supplierId) {
            return collect();
        }

        $openingData = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.account_code', 'like', '331%')
            ->where('jel.partner_type', 'supplier')
            ->where('jel.partner_id', $supplierId)
            ->where('je.entry_date', '<', $from)
            ->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')
            ->first();

        // TK 331 credit-normal
        $openingBalance = (float)($openingData?->cr ?? 0) - (float)($openingData?->dr ?? 0);

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
            ->select('je.entry_date as date', 'je.code as ref',
                DB::raw("COALESCE(jel.description, je.description, '') as description"),
                'jel.debit', 'jel.credit')
            ->get();

        $result  = [];
        $balance = $openingBalance;

        $result[] = (object)['date' => '', 'ref' => '', 'description' => 'Số dư đầu kỳ', 'debit' => 0, 'credit' => 0, 'balance' => $openingBalance];

        foreach ($lines as $l) {
            // TK 331: credit tăng nợ phải trả, debit giảm
            $balance += (float)$l->credit - (float)$l->debit;
            $result[] = (object)[
                'date'        => $l->date,
                'ref'         => $l->ref,
                'description' => $l->description,
                'debit'       => (float) $l->debit,
                'credit'      => (float) $l->credit,
                'balance'     => $balance,
            ];
        }

        $result[] = (object)['date' => '', 'ref' => '', 'description' => 'Số dư cuối kỳ', 'debit' => 0, 'credit' => 0, 'balance' => $balance];

        return collect($result);
    }

    public function headings(): array
    {
        return ['Ngày', 'Số CT', 'Diễn giải', 'Nợ (VND)', 'Có (VND)', 'Số dư (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->date,
            $row->ref,
            $row->description,
            $row->debit  > 0 ? $row->debit  : '',
            $row->credit > 0 ? $row->credit : '',
            $row->balance,
        ];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
