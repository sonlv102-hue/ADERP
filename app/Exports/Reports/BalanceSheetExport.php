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
 * B01a-DNN — Báo cáo tình hình tài chính.
 * Nhận kết quả đã tính từ FinancialPositionReportService — không tính lại.
 * 4 cột: Mã chỉ tiêu | Chỉ tiêu | Cuối kỳ (VND) | Đầu năm (VND).
 */
class BalanceSheetExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private array $reportData,
        private array $priorReportData = [],
    ) {}

    public function title(): string
    {
        return 'B01a-DNN';
    }

    public function collection(): Collection
    {
        $rows    = $this->reportData['rows']    ?? [];
        $summary = $this->reportData['summary'] ?? [];
        $asOf    = $this->reportData['as_of']   ?? '';

        // Build prior lookup by item_code
        $priorLookup = [];
        foreach ($this->priorReportData['rows'] ?? [] as $row) {
            if (isset($row['item_code'])) {
                $priorLookup[$row['item_code']] = $row['amount'] ?? 0;
            }
        }
        $priorAsOf = $this->priorReportData['as_of'] ?? '';

        $items = [];

        // Tiêu đề
        $items[] = (object) [
            'code'   => '',
            'name'   => 'BÁO CÁO TÌNH HÌNH TÀI CHÍNH — Mẫu B01a-DNN (TT 133/2016/TT-BTC)',
            'amount' => null,
            'prior'  => null,
            'bold'   => true,
        ];
        $items[] = (object) ['code' => '', 'name' => 'Tại ngày: ' . $asOf, 'amount' => null, 'prior' => null, 'bold' => false];
        $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'prior' => null, 'bold' => false];

        // Asset rows
        $items[] = (object) ['code' => '', 'name' => 'PHẦN I — TÀI SẢN', 'amount' => null, 'prior' => null, 'bold' => true];
        foreach (array_filter($rows, fn($r) => $r['section'] === 'asset') as $row) {
            $indent  = $row['level'] === 2 ? '    ' : '';
            $items[] = (object) [
                'code'   => $row['item_code'] ?? '',
                'name'   => $indent . $row['item_name'],
                'amount' => $row['amount'],
                'prior'  => $priorLookup[$row['item_code'] ?? ''] ?? null,
                'bold'   => $row['is_total'] || ($row['level'] === 1 && $row['is_formula']),
            ];
        }

        $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'prior' => null, 'bold' => false];

        // Source rows
        $items[] = (object) ['code' => '', 'name' => 'PHẦN II — NGUỒN VỐN', 'amount' => null, 'prior' => null, 'bold' => true];
        foreach (array_filter($rows, fn($r) => $r['section'] === 'source') as $row) {
            $indent  = $row['level'] === 2 ? '    ' : '';
            $items[] = (object) [
                'code'   => $row['item_code'] ?? '',
                'name'   => $indent . $row['item_name'],
                'amount' => $row['amount'],
                'prior'  => $priorLookup[$row['item_code'] ?? ''] ?? null,
                'bold'   => $row['is_total'] || $row['is_section_header']
                          || ($row['level'] === 1 && $row['is_formula']),
            ];
        }

        // Cảnh báo nếu không cân
        if (!($summary['balanced'] ?? true)) {
            $items[] = (object) ['code' => '', 'name' => '', 'amount' => null, 'prior' => null, 'bold' => false];
            $items[] = (object) [
                'code'   => '',
                'name'   => '⚠ Báo cáo chưa cân. Chênh lệch: ' . number_format(abs($summary['difference'] ?? 0), 0, ',', '.') . ' đ',
                'amount' => null,
                'prior'  => null,
                'bold'   => true,
            ];
        }

        return collect($items);
    }

    public function headings(): array
    {
        return ['Mã chỉ tiêu', 'Chỉ tiêu', 'Cuối kỳ (VND)', 'Đầu năm (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            $row->amount !== null ? $row->amount : '',
            $row->prior  !== null ? $row->prior  : '',
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
            if (in_array($cell, ['200', '300', '400', '500'])) {
                $styles[$i] = ['font' => ['bold' => true]];
            }
        }

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(55);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getStyle("C2:D{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return $styles;
    }
}
