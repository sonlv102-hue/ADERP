<?php

namespace App\Exports\Reports;

use App\Models\FixedAsset;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FixedAssetExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Tài sản cố định'; }

    public function collection(): Collection
    {
        $query = FixedAsset::query();
        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }
        if (!empty($this->filters['category'])) {
            $query->where('category', $this->filters['category']);
        }
        return $query->orderBy('acquisition_date')->get();
    }

    public function headings(): array
    {
        return ['Mã TSCĐ', 'Tên tài sản', 'Nhóm', 'Ngày mua', 'Nguyên giá', 'TL KH (%/năm)',
                'Khấu hao năm', 'Khấu hao tháng', 'Hao mòn lũy kế', 'Giá trị còn lại', 'Vị trí', 'Trạng thái'];
    }

    public function map($fa): array
    {
        return [
            $fa->code, $fa->name, $fa->category,
            $fa->acquisition_date?->format('d/m/Y'),
            $fa->acquisition_cost,
            $fa->depreciation_rate,
            $fa->annual_depreciation,
            $fa->monthly_depreciation,
            $fa->accumulated_depreciation,
            $fa->net_book_value,
            $fa->location,
            match($fa->status) {
                'active'             => 'Đang sử dụng',
                'disposed'           => 'Đã thanh lý',
                'fully_depreciated'  => 'Đã KH hết',
                default              => $fa->status,
            },
        ];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
