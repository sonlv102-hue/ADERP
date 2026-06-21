<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * B02-DNN — Báo cáo kết quả hoạt động kinh doanh.
 * Nhận rows đã tính từ IncomeStatementController — không tính lại.
 * 4 cột: Mã số | Chỉ tiêu | Năm nay (VND) | Năm trước (VND).
 */
class IncomeStatementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private array $currentRows,
        private array $priorRows,
        private string $dateFrom,
        private string $dateTo,
    ) {}

    public function title(): string { return 'B02-DNN'; }

    public function collection(): Collection
    {
        $priorByIndex = array_values($this->priorRows);
        $items        = [];

        // Tiêu đề
        $items[] = (object) ['code' => '', 'label' => 'BÁO CÁO KẾT QUẢ HOẠT ĐỘNG KINH DOANH — Mẫu B02-DNN (TT 133/2016/TT-BTC)', 'current' => null, 'prior' => null, 'bold' => true];
        $items[] = (object) ['code' => '', 'label' => 'Kỳ báo cáo: ' . $this->dateFrom . ' đến ' . $this->dateTo, 'current' => null, 'prior' => null, 'bold' => false];
        $items[] = (object) ['code' => '', 'label' => '', 'current' => null, 'prior' => null, 'bold' => false];

        foreach ($this->currentRows as $i => $row) {
            $prior   = $priorByIndex[$i] ?? null;
            $items[] = (object) [
                'code'    => $row['code'],
                'label'   => $row['label'],
                'current' => $row['amount'],
                'prior'   => $prior !== null ? $prior['amount'] : null,
                'bold'    => $row['bold'],
            ];
        }

        return collect($items);
    }

    public function headings(): array
    {
        return ['Mã số', 'Chỉ tiêu', 'Năm nay (VND)', 'Năm trước (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->label,
            $row->current !== null ? $row->current : '',
            $row->prior   !== null ? $row->prior   : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(55);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getStyle("C2:D{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }
}
