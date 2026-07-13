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

        // Tính số dư đầu kỳ
        $op331Data = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.partner_type', 'supplier')
            ->where('jel.partner_id', $supplierId)
            ->where('je.entry_date', '<', $from)
            ->where('jel.account_code', 'like', '331%')
            ->where('jel.account_code', 'not like', '331UT%')
            ->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')
            ->first();

        $op331utData = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jel.partner_type', 'supplier')
            ->where('jel.partner_id', $supplierId)
            ->where('je.entry_date', '<', $from)
            ->where('jel.account_code', 'like', '331UT%')
            ->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')
            ->first();

        $openingBal331 = (float)($op331Data?->cr ?? 0) - (float)($op331Data?->dr ?? 0);
        $openingBal331ut = (float)($op331utData?->dr ?? 0) - (float)($op331utData?->cr ?? 0);
        $openingBalNet = $openingBal331 - $openingBal331ut;

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
            ->select('je.entry_date as date', 'je.code as ref', 'jel.account_code',
                DB::raw("COALESCE(jel.description, je.description, '') as description"),
                'jel.debit', 'jel.credit')
            ->get();

        $result  = [];
        $running331 = $openingBal331;
        $running331ut = $openingBal331ut;

        $result[] = (object)[
            'date'         => '',
            'ref'          => '',
            'account_code' => '',
            'description'  => 'Số dư đầu kỳ',
            'debit'        => 0,
            'credit'       => 0,
            'balance_331'   => $openingBal331,
            'balance_331ut' => $openingBal331ut,
            'balance_net'   => $openingBalNet,
        ];

        foreach ($lines as $l) {
            if (str_starts_with($l->account_code, '331UT')) {
                $running331ut += (float)$l->debit - (float)$l->credit;
            } else {
                $running331 += (float)$l->credit - (float)$l->debit;
            }
            $result[] = (object)[
                'date'         => $l->date,
                'ref'          => $l->ref,
                'account_code' => $l->account_code,
                'description'  => $l->description,
                'debit'        => (float) $l->debit,
                'credit'       => (float) $l->credit,
                'balance_331'   => $running331,
                'balance_331ut' => $running331ut,
                'balance_net'   => $running331 - $running331ut,
            ];
        }

        $result[] = (object)[
            'date'         => '',
            'ref'          => '',
            'account_code' => '',
            'description'  => 'Số dư cuối kỳ',
            'debit'        => 0,
            'credit'       => 0,
            'balance_331'   => $running331,
            'balance_331ut' => $running331ut,
            'balance_net'   => $running331 - $running331ut,
        ];

        return collect($result);
    }

    public function headings(): array
    {
        return ['Ngày', 'Số CT', 'Tài khoản', 'Diễn giải', 'Nợ (VND)', 'Có (VND)', 'Số dư (331)', 'Ứng trước (331UT)', 'Công nợ ròng'];
    }

    public function map($row): array
    {
        return [
            $row->date,
            $row->ref,
            $row->account_code,
            $row->description,
            $row->debit  > 0 ? $row->debit  : '',
            $row->credit > 0 ? $row->credit : '',
            $row->balance_331,
            $row->balance_331ut,
            $row->balance_net,
        ];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
