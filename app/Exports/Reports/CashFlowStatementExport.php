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
 * B03-DNN — Báo cáo lưu chuyển tiền tệ (phương pháp trực tiếp).
 * Nhận dữ liệu đã tính từ CashFlowStatementService — không tính lại.
 */
class CashFlowStatementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $statement) {}

    public function title(): string { return 'B03-DNN'; }

    public function collection(): Collection
    {
        $items = [];

        $items[] = (object) ['code' => '', 'label' => 'BÁO CÁO LƯU CHUYỂN TIỀN TỆ — Mẫu B03-DNN (TT 133/2016/TT-BTC) — Phương pháp trực tiếp', 'amount' => null, 'bold' => true];
        $items[] = (object) ['code' => '', 'label' => 'Kỳ: ' . $this->statement['from'] . ' đến ' . $this->statement['to'], 'amount' => null, 'bold' => false];
        $items[] = (object) ['code' => '60', 'label' => 'Tiền và tương đương tiền đầu kỳ', 'amount' => $this->statement['opening_cash'], 'bold' => true];
        $items[] = (object) ['code' => '', 'label' => '', 'amount' => null, 'bold' => false];

        foreach ($this->statement['sections'] as $section) {
            $items[] = (object) ['code' => $section['code'], 'label' => $section['label'], 'amount' => null, 'bold' => true];

            foreach ($section['lines'] as $line) {
                if ($line['amount'] != 0) {
                    $items[] = (object) ['code' => $line['code'], 'label' => '    ' . $line['label'], 'amount' => $line['amount'], 'bold' => false];
                }
            }

            $items[] = (object) [
                'code'   => $section['net_code'],
                'label'  => 'Lưu chuyển tiền thuần từ ' . ($section['code'] === 'I' ? 'HĐKD' : ($section['code'] === 'II' ? 'HĐĐT' : 'HĐTC')),
                'amount' => $section['net'],
                'bold'   => true,
            ];
            $items[] = (object) ['code' => '', 'label' => '', 'amount' => null, 'bold' => false];
        }

        $items[] = (object) ['code' => '50', 'label' => 'Lưu chuyển tiền thuần trong kỳ (50 = 20 + 30 + 40)', 'amount' => $this->statement['net_total'], 'bold' => true];
        $items[] = (object) ['code' => '60', 'label' => 'Tiền và tương đương tiền đầu kỳ', 'amount' => $this->statement['opening_cash'], 'bold' => false];
        $items[] = (object) ['code' => '70', 'label' => 'Tiền và tương đương tiền cuối kỳ (70 = 50 + 60)', 'amount' => $this->statement['closing_cash'], 'bold' => true];

        return collect($items);
    }

    public function headings(): array
    {
        return ['Mã số', 'Chỉ tiêu', 'Số tiền (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->label,
            $row->amount !== null ? $row->amount : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(65);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getStyle("C2:C{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }
}
