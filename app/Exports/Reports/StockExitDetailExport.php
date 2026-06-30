<?php

namespace App\Exports\Reports;

use App\Services\Reports\StockMovementDetailReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockExitDetailExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        return 'Chi tiết xuất kho';
    }

    public function collection()
    {
        return (new StockMovementDetailReportService())->buildStockExitRows($this->filters);
    }

    public function headings(): array
    {
        return [
            'Mã phiếu xuất',
            'Ngày xuất',
            'Kho',
            'Khách hàng',
            'Lý do',
            'Mã SP',
            'Tên SP',
            'ĐVT',
            'Số lượng',
            'Đơn giá',
            'Giá trị',
        ];
    }

    public function map($row): array
    {
        return [
            $row['document_code'],
            $row['document_date'],
            $row['warehouse'],
            $row['partner'],
            $row['reason'],
            $row['product_code'],
            $row['product_name'],
            $row['unit'],
            $row['quantity'],
            $row['unit_price'],
            $row['total_cost'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
