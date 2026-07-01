<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CashFlowStatementExport implements WithMultipleSheets
{
    public function __construct(
        private array $report,
        private array $company
    ) {}

    public function sheets(): array
    {
        return [new CashFlowStatementSheet($this->report, $this->company)];
    }
}

class CashFlowStatementSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths, WithEvents
{
    private int $dataStartRow = 10; // row where table header sits
    private array $boldRows   = [];
    private array $sectionRows = [];
    private int $totalRows    = 0;

    public function __construct(
        private array $report,
        private array $company
    ) {}

    public function title(): string { return 'B03-DNN'; }

    private function fmtDate(?string $date): string
    {
        return $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : '';
    }

    public function array(): array
    {
        $period          = $this->report['period'] ?? null;
        $comparison      = $this->report['comparison_period'] ?? null;
        $periodLabel     = $period['label'] ?? ('Năm ' . $this->report['year']);
        $comparisonLabel = $comparison['label'] ?? 'Kỳ so sánh';
        $rows       = $this->report['rows'];
        $unitLabel  = match ($this->report['unit']) {
            'nghin_dong'  => 'Nghìn đồng',
            'trieu_dong'  => 'Triệu đồng',
            default       => 'Đồng',
        };
        $companyName    = $this->company['company_name']    ?? '';
        $companyAddress = $this->company['company_address'] ?? '';

        $data = [];

        // Row 1-2: company info (left) + template code (right merged later)
        $data[] = [$companyName, '', '', '', 'Mẫu số B03-DNN'];
        $data[] = [$companyAddress, '', '', '', '(Ban hành theo Thông tư số 133/2016/TT-BTC'];
        $data[] = ['', '', '', '', 'ngày 26/8/2016 của Bộ Tài chính)'];
        $data[] = [''];
        // Row 5: title
        $data[] = ['BÁO CÁO LƯU CHUYỂN TIỀN TỆ', '', '', '', ''];
        // Row 6
        $data[] = ['(Theo phương pháp trực tiếp)', '', '', '', ''];
        // Row 7
        $data[] = [$periodLabel, '', '', '', ''];
        // Row 8
        $data[] = [$period ? ('Kỳ báo cáo: Từ ngày ' . $this->fmtDate($period['date_from']) . ' đến ngày ' . $this->fmtDate($period['date_to'])) : '', '', '', "Đơn vị tính: {$unitLabel}", ''];
        // Row 9
        $data[] = ['Nguồn số liệu: Bút toán GL đã posted', '', '', '', ''];
        // Row 10: blank
        $data[] = [''];

        // Row 11: table header
        $data[] = ['Chỉ tiêu', 'Mã số', 'Thuyết minh', $periodLabel, $comparisonLabel];
        $this->dataStartRow = count($data); // 1-based

        $sectionHeaderCodes = ['I', 'II', 'III'];
        $currentSection = null;

        foreach ($rows as $row) {
            $section = $row['section'];
            // Print section header when section changes
            if ($section && $section !== $currentSection) {
                $headerLabel = match ($section) {
                    'I'   => 'I. Lưu chuyển tiền từ hoạt động kinh doanh',
                    'II'  => 'II. Lưu chuyển tiền từ hoạt động đầu tư',
                    'III' => 'III. Lưu chuyển tiền từ hoạt động tài chính',
                    default => $section,
                };
                $data[] = [$headerLabel, '', '', '', ''];
                $this->sectionRows[] = count($data);
                $currentSection = $section;
            }

            if ($row['code'] === '60' || $row['code'] === '61') {
                // These are not inside any section
            }

            $curr = $row['curr'] !== 0 ? $row['curr'] : null;
            $prev = $row['prev'] !== 0 ? $row['prev'] : null;

            $label = $row['is_summary'] ? $row['label'] : '    ' . $row['label'];
            $data[] = [$label, $row['code'], $row['note'] ?? '', $curr, $prev];

            if ($row['is_summary']) {
                $this->boldRows[] = count($data);
            }
        }

        // Spacer + signature
        $data[] = [''];
        $sigRow = count($data) + 1;
        $data[] = ['', 'Lập, ngày ... tháng ... năm ' . substr($this->report['period']['date_to'] ?? (string) $this->report['year'], 0, 4), '', '', ''];
        $data[] = [''];
        $data[] = ['Người lập biểu', '', 'Kế toán trưởng', '', 'Người đại diện theo pháp luật'];
        $data[] = ['(Ký, họ tên)', '', '(Ký, họ tên)', '', '(Ký, họ tên, đóng dấu)'];
        $data[] = [''];
        $data[] = [''];
        $data[] = [''];
        $data[] = [''];
        $data[] = ['(Ghi rõ họ tên)', '', '(Ghi rõ họ tên)', '', '(Ghi rõ họ tên)'];

        $this->totalRows = count($data);
        return $data;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 58,
            'B' => 10,
            'C' => 14,
            'D' => 18,
            'E' => 18,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $this->applyStyles($sheet);
            },
        ];
    }

    private function applyStyles(Worksheet $sheet): void
    {
        // Company name bold
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);
        $sheet->mergeCells('E1:E1');

        // Template code right-align
        $sheet->getStyle('E1:E3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('E1:E3')->getFont()->setItalic(true)->setSize(9);

        // Title rows (5-7) centered, merged A:E
        foreach ([5, 6, 7] as $r) {
            $sheet->mergeCells("A{$r}:E{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A6')->getFont()->setItalic(true);
        $sheet->getStyle('A7')->getFont()->setItalic(true);

        // Row 8: kỳ báo cáo (trái) + đơn vị tính (phải)
        $sheet->mergeCells('A8:C8');
        $sheet->mergeCells('D8:E8');
        $sheet->getStyle('D8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A8:E8')->getFont()->setItalic(true)->setSize(9);

        // Row 9: nguồn số liệu
        $sheet->mergeCells('A9:E9');
        $sheet->getStyle('A9')->getFont()->setItalic(true)->setSize(8);

        // Table header row 11
        $headerRow = 11;
        $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'D0E4F7']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        // Data rows: border + number format
        $lastDataRow = $headerRow + count($this->report['rows']) + 3 + count($this->sectionRows); // approximate
        $actualHigh  = $sheet->getHighestRow();
        $tableEnd    = min($actualHigh, $headerRow + 100);

        for ($r = $headerRow + 1; $r <= $tableEnd; $r++) {
            $cellA = $sheet->getCell("A{$r}")->getValue();
            if ($cellA === '' || $cellA === null) {
                continue;
            }
            // Skip signature rows (no borders)
            if (str_contains((string) $cellA, 'Người lập') || str_contains((string) $cellA, '(Ký') || str_contains((string) $cellA, '(Ghi')) {
                $sheet->getStyle("A{$r}:E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                continue;
            }
            if (str_contains((string) $cellA, 'Lập, ngày')) {
                $sheet->mergeCells("B{$r}:E{$r}");
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                continue;
            }
            $sheet->getStyle("A{$r}:E{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            // Center mã số, thuyết minh
            $sheet->getStyle("B{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            // Right-align amounts
            $sheet->getStyle("D{$r}:E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("D{$r}:E{$r}")->getNumberFormat()->setFormatCode('#,##0');
        }

        // Bold section headers
        foreach ($this->sectionRows as $r) {
            $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EFF6FF']],
            ]);
            $sheet->mergeCells("A{$r}:E{$r}");
        }

        // Bold summary rows
        foreach ($this->boldRows as $r) {
            $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F0FDF4']],
            ]);
        }

        // Signature section: merge columns
        $high = $sheet->getHighestRow();
        for ($r = $tableEnd + 2; $r <= $high; $r++) {
            $sheet->mergeCells("A{$r}:B{$r}");
            $sheet->mergeCells("D{$r}:E{$r}");
        }
    }
}
