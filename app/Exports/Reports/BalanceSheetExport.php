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
 * Export Báo cáo tình hình tài chính B01a-DNN.
 * Nhận kết quả đã tính từ FinancialPositionReportService — không tính lại.
 */
class BalanceSheetExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $reportData) {}

    public function title(): string
    {
        return 'B01a-DNN';
    }

    public function collection(): Collection
    {
        $rows    = $this->reportData['rows']    ?? [];
        $summary = $this->reportData['summary'] ?? [];
        $asOf    = $this->reportData['as_of']   ?? '';

        $items = [];

        // Tiêu đề
        $items[] = (object) [
            'code'   => '',
            'name'   => 'BÁO CÁO TÌNH HÌNH TÀI CHÍNH — Mẫu B01a-DNN (TT 133/2016/TT-BTC)',
            'amount' => null,
            'bold'   => true,
        ];
        $items[] = (object) ['code' => '', 'name' => 'Tại ngày: ' . $asOf, 'amount' => null, 'bold' => false];
        $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'bold' => false];

        // Asset rows
        $items[] = (object) ['code' => '', 'name' => 'PHẦN I — TÀI SẢN', 'amount' => null, 'bold' => true];
        foreach (array_filter($rows, fn($r) => $r['section'] === 'asset') as $row) {
            $indent  = $row['level'] === 2 ? '    ' : '';
            $items[] = (object) [
                'code'   => $row['item_code'] ?? '',
                'name'   => $indent . $row['item_name'],
                'amount' => $row['amount'],
                'bold'   => $row['is_total'] || ($row['level'] === 1 && $row['is_formula']),
            ];
        }

        $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'bold' => false];

        // Source rows
        $items[] = (object) ['code' => '', 'name' => 'PHẦN II — NGUỒN VỐN', 'amount' => null, 'bold' => true];
        foreach (array_filter($rows, fn($r) => $r['section'] === 'source') as $row) {
            $indent  = $row['level'] === 2 ? '    ' : '';
            $items[] = (object) [
                'code'   => $row['item_code'] ?? '',
                'name'   => $indent . $row['item_name'],
                'amount' => $row['amount'],
                'bold'   => $row['is_total'] || $row['is_section_header']
                          || ($row['level'] === 1 && $row['is_formula']),
            ];
        }

        // Cảnh báo nếu không cân
        if (!($summary['balanced'] ?? true)) {
            $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'bold' => false];
            $items[] = (object) [
                'code'   => '',
                'name'   => '⚠ Báo cáo chưa cân. Chênh lệch: ' . number_format(abs($summary['difference'] ?? 0), 0, ',', '.') . ' đ',
                'amount' => null,
                'bold'   => true,
            ];
        }

        return collect($items);
    }

    public function headings(): array
    {
        return ['Mã chỉ tiêu', 'Chỉ tiêu', 'Số tiền (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            $row->amount !== null ? $row->amount : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $styles     = [
            1 => ['font' => ['bold' => true]],
        ];

        for ($i = 1; $i <= $highestRow; $i++) {
            $cell = $sheet->getCell("A{$i}")->getValue();
            // Bold rows: mã 200, 300, 400, 500
            if (in_array($cell, ['200', '300', '400', '500'])) {
                $styles[$i] = ['font' => ['bold' => true]];
            }
        }

        // Căn phải cột số tiền
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(55);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getStyle("C2:C{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return $styles;
    }
}
