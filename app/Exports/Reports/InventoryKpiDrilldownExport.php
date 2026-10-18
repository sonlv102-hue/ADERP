<?php

namespace App\Exports\Reports;

use App\Services\Reports\InventoryKpiDrilldownQuery;
use App\Services\Reports\InventoryKpiDrilldownService;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export chứng từ của 1 KPI drill-down. Dùng FromQuery (chunk) thay vì FromCollection
 * vì tab tồn đầu/cuối kỳ có thể trả toàn bộ lịch sử movement trước/đến 1 ngày — tránh
 * load hết vào memory. Query/điều kiện lọc lấy nguyên từ InventoryKpiDrilldownService
 * để đảm bảo khớp dữ liệu UI.
 */
class InventoryKpiDrilldownExport implements FromQuery, WithHeadings, WithMapping, WithStyles, WithTitle
{
    private const KPI_TITLE = [
        'begin' => 'Chứng từ - Tồn đầu kỳ',
        'in'    => 'Chứng từ - Nhập trong kỳ',
        'out'   => 'Chứng từ - Xuất trong kỳ',
        'end'   => 'Chứng từ - Tồn cuối kỳ',
    ];

    public function __construct(private array $filters, private string $kpi) {}

    public function title(): string
    {
        return self::KPI_TITLE[$this->kpi] ?? 'Chứng từ tồn kho';
    }

    public function query()
    {
        return (new InventoryKpiDrilldownService())->query($this->filters, $this->kpi)
            ->orderBy('sm.id');
    }

    public function headings(): array
    {
        return [
            'Loại nghiệp vụ', 'Ngày', 'Số chứng từ', 'Mã SP', 'Tên SP', 'ĐVT', 'Kho',
            'SL', 'Đơn giá vốn', 'Giá trị', 'Giá trị nguồn?', 'Diễn giải',
        ];
    }

    public function map($row): array
    {
        $mapped = InventoryKpiDrilldownQuery::map($row);

        return [
            $mapped['type_label'],
            $mapped['doc_date'],
            $mapped['doc_code'],
            $mapped['product_code'],
            $mapped['product_name'],
            $mapped['unit'],
            $mapped['warehouse_name'],
            $mapped['quantity'],
            $mapped['unit_cost'] ?? '',
            $mapped['amount'] ?? '',
            $mapped['has_source_value'] ? 'Có' : 'Chưa có giá trị nguồn',
            $mapped['notes'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
