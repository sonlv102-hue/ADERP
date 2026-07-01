<?php

namespace App\Exports\Reports;

use App\Services\Accounting\IncomeStatementService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class IncomeStatementExport implements WithMultipleSheets
{
    public function __construct(
        private array $report,
        private array $company,
    ) {}

    public function sheets(): array
    {
        return [new IncomeStatementSheet($this->report, $this->company)];
    }
}

class IncomeStatementSheet implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    private int $headerRows = 9;   // rows before table header
    private int $tableHeader = 10; // row number of column headers
    private int $dataStart  = 11;  // first data row

    public function __construct(
        private array $report,
        private array $company,
    ) {}

    public function title(): string { return 'B02-DNN'; }

    private function fmtDate(?string $date): string
    {
        return $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : '';
    }

    public function columnWidths(): array
    {
        return ['A' => 52, 'B' => 10, 'C' => 14, 'D' => 18, 'E' => 18];
    }

    public function array(): array
    {
        $period    = $this->report['period'] ?? null;
        $comparison = $this->report['comparison_period'] ?? null;
        $periodLabel     = $period['label'] ?? ('Năm ' . $this->report['year']);
        $comparisonLabel = $comparison['label'] ?? 'Kỳ so sánh';
        $unitLbl = match ($this->report['unit']) {
            'nghin_dong'  => 'Nghìn đồng',
            'trieu_dong'  => 'Triệu đồng',
            default       => 'Đồng',
        };

        $rows = [];
        $rows[] = [$this->company['company_name'] ?? '', '', '', 'Mẫu số B02-DNN', ''];
        $rows[] = ['Địa chỉ: ' . ($this->company['company_address'] ?? ''), '', '', '(Ban hành theo Thông tư số 133/2016/TT-BTC', ''];
        $rows[] = ['', '', '', 'ngày 26/8/2016 của Bộ Tài chính)', ''];
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['BÁO CÁO KẾT QUẢ HOẠT ĐỘNG KINH DOANH', '', '', '', ''];
        $rows[] = [$periodLabel, '', '', '', ''];
        $rows[] = [
            $period ? "Kỳ báo cáo: Từ ngày {$this->fmtDate($period['date_from'])} đến ngày {$this->fmtDate($period['date_to'])}" : '',
            '', '', "Đơn vị tính: {$unitLbl}", '',
        ];
        $rows[] = ['Nguồn số liệu: Bút toán GL đã posted', '', '', '', ''];
        $rows[] = ['', '', '', '', ''];

        // Table header
        $rows[] = ['CHỈ TIÊU', 'Mã số', 'Thuyết minh', $periodLabel, $comparisonLabel];

        // Data rows
        foreach ($this->report['rows'] as $row) {
            $rows[] = [
                $row['label'],
                $row['code'],
                $row['note'] ?? '',
                $row['curr'] ?: '',
                $row['prev'] ?: '',
            ];
        }

        // Signature block
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['Lập, ngày ... tháng ... năm ' . substr($this->report['period']['date_to'] ?? (string) $this->report['year'], 0, 4), '', '', '', ''];
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['Người lập biểu', '', '', 'Kế toán trưởng', 'Người đại diện theo pháp luật'];
        $rows[] = ['(Ký, họ tên)', '', '', '(Ký, họ tên)', '(Ký, họ tên, đóng dấu)'];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet    = $event->sheet->getDelegate();
                $dataRows = $this->report['rows'];
                $dataEnd  = $this->dataStart + count($dataRows) - 1;

                // Header merges
                $sheet->mergeCells('A1:C1');
                $sheet->mergeCells('D1:E1');
                $sheet->mergeCells('A2:C2');
                $sheet->mergeCells('D2:E2');
                $sheet->mergeCells('A3:C3');
                $sheet->mergeCells('D3:E3');
                $sheet->mergeCells('A5:E5');
                $sheet->mergeCells('A6:E6');
                $sheet->mergeCells('A7:C7');
                $sheet->mergeCells('D7:E7');
                $sheet->mergeCells('A8:E8');

                $sheet->getStyle('A5')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle('A6')->applyFromArray([
                    'font'      => ['italic' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle('A7:E8')->applyFromArray([
                    'font' => ['italic' => true, 'size' => 8],
                ]);
                $sheet->getStyle('D7:E7')->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                ]);
                $sheet->getStyle('D1:E3')->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                    'font'      => ['italic' => true, 'size' => 8],
                ]);
                $sheet->getStyle('D1')->applyFromArray(['font' => ['bold' => true, 'italic' => false, 'size' => 9]]);

                // Table header row
                $sheet->getStyle("A{$this->tableHeader}:E{$this->tableHeader}")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '93c5fd']]],
                ]);
                $sheet->getRowDimension($this->tableHeader)->setRowHeight(24);

                // Data rows
                for ($i = 0; $i < count($dataRows); $i++) {
                    $row   = $dataRows[$i];
                    $exRow = $this->dataStart + $i;

                    $sheet->getStyle("D{$exRow}:E{$exRow}")->getNumberFormat()
                        ->setFormatCode('#,##0');

                    $baseStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]]];

                    if ($row['isSummary']) {
                        $baseStyle['font'] = ['bold' => true];
                        $baseStyle['fill'] = ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F0FDF4']];
                    }
                    $sheet->getStyle("A{$exRow}:E{$exRow}")->applyFromArray($baseStyle);

                    $sheet->getStyle("B{$exRow}:C{$exRow}")->applyFromArray([
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $sheet->getStyle("D{$exRow}:E{$exRow}")->applyFromArray([
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                    ]);
                }

                // Signature block
                $signRow = $dataEnd + 3;
                $sheet->getStyle("A{$signRow}")->applyFromArray(['font' => ['italic' => true]]);
                $signHead = $signRow + 2;
                $sheet->getStyle("A{$signHead}:E{$signHead}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            },
        ];
    }
}
