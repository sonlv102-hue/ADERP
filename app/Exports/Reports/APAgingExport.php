<?php

namespace App\Exports\Reports;

use App\Services\ArApLedgerService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class APAgingExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Công nợ phải trả'; }

    public function collection(): Collection
    {
        $ledger = app(ArApLedgerService::class);
        return $ledger->payables($this->filters, onlyOutstanding: false);
    }

    public function headings(): array
    {
        return [
            'Số HĐ/CT', 'Nguồn', 'Nhà cung cấp', 'Ngày CT', 'Hạn thanh toán',
            'Tổng tiền', 'Đã trả', 'Còn lại', 'Trạng thái', 'Tình trạng nợ',
        ];
    }

    public function map($row): array
    {
        return [
            $row['code'],
            $row['source_type'] === 'opening_balance' ? 'Đầu kỳ' : 'Hóa đơn',
            $row['partner_name'],
            $row['doc_date'] ?? '—',
            $row['due_date'] ?? '—',
            $row['total'],
            $row['paid'],
            $row['remaining'],
            $row['status_label'],
            $row['bucket'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
