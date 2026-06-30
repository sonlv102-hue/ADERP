<?php

namespace App\Exports\Reports;

use App\Services\Reports\InventoryReportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Báo cáo tồn kho'; }

    public function collection()
    {
        // Dùng cùng service và cùng query logic với UI — không tự query lại
        return (new InventoryReportService())->buildAllRows($this->filters);
    }

    public function headings(): array
    {
        return [
            'Mã SP', 'Tên SP', 'ĐVT', 'Danh mục',
            'Tồn đầu kỳ (SL)', 'Giá trị đầu kỳ',
            'Nhập (SL)', 'Ngày nhập g.nhất', 'TT nhập',
            'Xuất (SL)', 'Ngày xuất g.nhất', 'TT xuất',
            'Tồn cuối kỳ (SL)', 'Giá trị tồn cuối',
        ];
    }

    public function map($row): array
    {
        // $row đã là array từ InventoryReportService::mapRow() — map trực tiếp
        return [
            $row['code'],
            $row['name'],
            $row['unit'],
            $row['category'],
            $row['stock_begin'],
            $row['value_begin'],      // SUM(sm.amount) — không tính lại bằng cost_price
            $row['stock_in'],
            $row['last_in_date'] ?? '',
            $row['value_in'],
            $row['stock_out'],
            $row['last_out_date'] ?? '',
            $row['value_out'],
            $row['stock_end'],
            $row['value_end'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
