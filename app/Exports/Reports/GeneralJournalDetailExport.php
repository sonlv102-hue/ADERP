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
 * Sổ nhật ký chung chi tiết — mỗi journal_entry_line xuất thành 1 hàng riêng.
 */
class GeneralJournalDetailExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Sổ NKC chi tiết'; }

    public function collection(): Collection
    {
        $year        = (int) ($this->filters['year'] ?? now()->year);
        $from        = $this->filters['date_from'] ?? "{$year}-01-01";
        $to          = $this->filters['date_to']   ?? "{$year}-12-31";
        $accountCode = $this->filters['account_code'] ?? null;

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
                'je.code as ref', 'je.entry_date as date', 'je.description as entry_description',
                'je.source_type', 'je.status', 'je.posted_at', 'u.name as created_by_name',
                'jel.account_code', 'coa.name as account_name', 'jel.description as line_description',
                'jel.debit', 'jel.credit', 'jel.partner_type', 'jel.partner_id',
                'prj.name as project_name', 'prj.code as project_code',
            ])
            ->get();

        $partnerNames = $this->resolvePartnerNames($lines);

        $result = [];
        $seq    = 1;

        foreach ($lines as $line) {
            $partnerName = ($line->partner_type && $line->partner_id)
                ? ($partnerNames[$line->partner_type][(int) $line->partner_id] ?? null)
                : null;

            $result[] = (object) [
                'seq'               => $seq++,
                'date'              => $line->date,
                'ref'               => $line->ref,
                'entry_description' => $line->entry_description,
                'account_code'      => $line->account_code,
                'account_name'      => $line->account_name ?? '',
                'line_description'  => $line->line_description ?: $line->entry_description,
                'debit'             => (float) $line->debit,
                'credit'            => (float) $line->credit,
                'partner_name'      => $partnerName ?? '',
                'project_name'      => $line->project_name ? "{$line->project_code} - {$line->project_name}" : '',
                'source_type'       => $line->source_type ?: '',
                'status'            => $line->status,
                'created_by_name'   => $line->created_by_name ?? '',
                'posted_at'         => $line->posted_at,
            ];
        }

        return collect($result);
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

    public function headings(): array
    {
        return [
            'STT', 'Ngày', 'Số CT', 'Diễn giải bút toán', 'TK', 'Tên tài khoản', 'Diễn giải dòng',
            'Nợ (VND)', 'Có (VND)', 'Đối tượng', 'Dự án', 'Nguồn chứng từ', 'Trạng thái',
            'Người tạo', 'Thời gian hạch toán',
        ];
    }

    public function map($row): array
    {
        return [
            $row->seq,
            $row->date,
            $row->ref,
            $row->entry_description,
            $row->account_code,
            $row->account_name,
            $row->line_description,
            $row->debit  > 0 ? $row->debit  : '',
            $row->credit > 0 ? $row->credit : '',
            $row->partner_name,
            $row->project_name,
            $row->source_type,
            $row->status,
            $row->created_by_name,
            $row->posted_at,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(35);
        $sheet->getColumnDimension('E')->setWidth(8);
        $sheet->getColumnDimension('F')->setWidth(25);
        $sheet->getColumnDimension('G')->setWidth(35);
        $sheet->getColumnDimension('H')->setWidth(16);
        $sheet->getColumnDimension('I')->setWidth(16);
        $sheet->getStyle("H2:I{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }
}
