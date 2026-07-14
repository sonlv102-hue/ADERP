<?php

namespace App\Exports\Reports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProfitReportExport implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    private int $cardsStart;
    private int $tableHeader;
    private int $dataStart;

    public function __construct(
        private array $summary,
        private array $rows,
        private array $period,
        private array $company,
    ) {}

    public function title(): string
    {
        return 'Báo cáo lợi nhuận';
    }

    public function columnWidths(): array
    {
        return ['A' => 26, 'B' => 18, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 18, 'I' => 18, 'J' => 12];
    }

    public function array(): array
    {
        $rows = [];
        $rows[] = [$this->company['company_name'] ?? '', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Địa chỉ: ' . ($this->company['company_address'] ?? ''), '', '', '', '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['BÁO CÁO LỢI NHUẬN', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Kỳ báo cáo: ' . ($this->period['label'] ?? ''), '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Ngày in: ' . now()->format('d/m/Y'), '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Nguồn số liệu: Bút toán GL đã posted', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', '', '', '', ''];

        // Cards tổng quan
        $this->cardsStart = count($rows) + 1;
        $rows[] = ['TỔNG QUAN', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Doanh thu thuần', $this->summary['net_revenue'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Giá vốn', $this->summary['cogs'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Lợi nhuận gộp', $this->summary['gross_profit'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Tổng chi phí hoạt động', $this->summary['total_operating_expense'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Lợi nhuận thuần', $this->summary['net_profit'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Biên lợi nhuận gộp (%)', $this->summary['gross_margin'], '', '', '', '', '', '', '', ''];
        $rows[] = ['Biên lợi nhuận thuần (%)', $this->summary['net_margin'], '', '', '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', '', '', '', ''];

        // Bảng theo kỳ
        $this->tableHeader = count($rows) + 1;
        $rows[] = ['Kỳ', 'Doanh thu', 'Giá vốn', 'LN gộp', 'CP bán hàng', 'CP quản lý', 'CP tài chính', 'CP khác', 'LN thuần', 'Tỷ suất LN thuần (%)'];

        $this->dataStart = count($rows) + 1;
        foreach ($this->rows as $row) {
            $rows[] = [
                $row['label'],
                $row['net_revenue'],
                $row['cogs'],
                $row['gross_profit'],
                $row['selling_expense'],
                $row['admin_expense'],
                $row['financial_expense'],
                $row['other_expense'],
                $row['net_profit'],
                $row['net_margin'],
            ];
        }

        // Signature block
        $rows[] = ['', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', '', '', '', ''];
        $rows[] = ['Người lập biểu', '', '', 'Kế toán trưởng', '', '', 'Giám đốc', '', '', ''];
        $rows[] = ['(Ký, họ tên)', '', '', '(Ký, họ tên)', '', '', '(Ký, họ tên, đóng dấu)', '', '', ''];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $dataEnd = $this->dataStart + count($this->rows) - 1;

                $sheet->mergeCells('A4:J4');
                $sheet->getStyle('A4')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->mergeCells('A5:J5');
                $sheet->getStyle('A5')->applyFromArray(['alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);

                $sheet->getStyle("A{$this->cardsStart}")->applyFromArray(['font' => ['bold' => true]]);
                for ($i = $this->cardsStart + 1; $i <= $this->cardsStart + 7; $i++) {
                    $sheet->getStyle("B{$i}")->getNumberFormat()->setFormatCode('#,##0.##');
                }

                // Table header
                $sheet->getStyle("A{$this->tableHeader}:J{$this->tableHeader}")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                ]);

                for ($i = $this->dataStart; $i <= $dataEnd; $i++) {
                    $sheet->getStyle("B{$i}:J{$i}")->getNumberFormat()->setFormatCode('#,##0.##');
                    $sheet->getStyle("A{$i}:J{$i}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                    ]);
                }

                $signHead = $dataEnd + 3;
                $sheet->getStyle("A{$signHead}:J{$signHead}")->applyFromArray(['font' => ['bold' => true]]);
            },
        ];
    }
}
