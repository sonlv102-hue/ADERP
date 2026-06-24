<?php

namespace App\Exports\Reports;

use App\Http\Controllers\Reports\DocumentChecklistController;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class VoucherListingExport implements FromCollection, WithTitle, WithColumnWidths, WithEvents
{
    private array $rows;
    private array $totals;
    private array $filters;
    private array $company;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
        $ctrl          = new DocumentChecklistController();
        [$this->rows, $this->totals] = $ctrl->buildReport($filters);
        $this->company = Setting::getGroup('company');
    }

    public function title(): string
    {
        return 'Bảng kê chứng từ';
    }

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 14, // Ngày CT
            'B' => 18, // Số CT
            'C' => 28, // Tên khách / Đối tượng
            'D' => 40, // Diễn giải
            'E' => 12, // Tài khoản
            'F' => 14, // TK đối ứng
            'G' => 18, // Số phát sinh Nợ
            'H' => 18, // Số phát sinh Có
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet    = $event->sheet->getDelegate();
                $rows     = $this->rows;
                $company  = $this->company;
                $filters  = $this->filters;

                // ── Header rows ─────────────────────────────────────────────
                $sheet->insertNewRowBefore(1, 6);

                $companyName = $company['name'] ?? '';
                $address     = $company['address'] ?? '';

                $sheet->setCellValue('A1', $companyName);
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);

                $sheet->setCellValue('A2', $address);
                $sheet->getStyle('A2')->getFont()->setSize(9);

                $sheet->mergeCells('A4:H4');
                $sheet->setCellValue('A4', 'BẢNG KÊ CHỨNG TỪ');
                $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $dateLabel = 'Từ ngày: ' . date('d/m/Y', strtotime($filters['date_from']))
                           . '   Đến ngày: ' . date('d/m/Y', strtotime($filters['date_to']));
                $sheet->mergeCells('A5:H5');
                $sheet->setCellValue('A5', $dateLabel);
                $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A5')->getFont()->setItalic(true)->setSize(9);

                // ── Table header (row 7 = merged groups, row 8 = column names) ─
                $headerRow1 = 7;
                $headerRow2 = 8;
                $sheet->insertNewRowBefore($headerRow1, 2);

                // Group: CHỨNG TỪ (A7:B7)
                $sheet->mergeCells("A{$headerRow1}:B{$headerRow1}");
                $sheet->setCellValue("A{$headerRow1}", 'CHỨNG TỪ');
                // Group: SỐ PHÁT SINH (G7:H7)
                $sheet->mergeCells("G{$headerRow1}:H{$headerRow1}");
                $sheet->setCellValue("G{$headerRow1}", 'SỐ PHÁT SINH');

                $groupCells = ["A{$headerRow1}:B{$headerRow1}", "G{$headerRow1}:H{$headerRow1}"];
                foreach ($groupCells as $range) {
                    $sheet->getStyle($range)->applyFromArray([
                        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                }

                // Column headers row 8
                $headers = ['NGÀY', 'SỐ CT', 'TÊN KHÁCH', 'DIỄN GIẢI', 'TÀI KHOẢN', 'TK ĐỐI ỨNG', 'NỢ', 'CÓ'];
                $cols    = range('A', 'H');
                foreach ($headers as $i => $label) {
                    $cell = $cols[$i] . $headerRow2;
                    $sheet->setCellValue($cell, $label);
                    $sheet->getStyle($cell)->applyFromArray([
                        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '93c5fd']]],
                    ]);
                }

                // ── Data rows ────────────────────────────────────────────────
                $dataStart = $headerRow2 + 1;
                foreach ($rows as $ri => $row) {
                    $r = $dataStart + $ri;
                    $sheet->setCellValue("A{$r}", date('d/m/Y', strtotime($row['date'])));
                    $sheet->setCellValue("B{$r}", $row['je_code']);
                    $sheet->setCellValue("C{$r}", $row['object_name']);
                    $sheet->setCellValue("D{$r}", $row['description']);
                    $sheet->setCellValue("E{$r}", $row['account_code']);
                    $sheet->setCellValue("F{$r}", $row['counter_account']);
                    $sheet->setCellValue("G{$r}", $row['debit']  > 0 ? $row['debit']  : '');
                    $sheet->setCellValue("H{$r}", $row['credit'] > 0 ? $row['credit'] : '');

                    $sheet->getStyle("A{$r}:H{$r}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
                        'font'    => ['size' => 9],
                    ]);
                    $sheet->getStyle("G{$r}:H{$r}")->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle("A{$r}:B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("E{$r}:F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("G{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    if ($ri % 2 === 1) {
                        $sheet->getStyle("A{$r}:H{$r}")->getFill()
                            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
                    }
                }

                // ── Total row ────────────────────────────────────────────────
                $totalRow = $dataStart + count($rows);
                $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
                $sheet->setCellValue("A{$totalRow}", 'TỔNG CỘNG');
                $sheet->setCellValue("G{$totalRow}", $this->totals['debit']);
                $sheet->setCellValue("H{$totalRow}", $this->totals['credit']);
                $sheet->getStyle("A{$totalRow}:H{$totalRow}")->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EFF6FF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                ]);
                $sheet->getStyle("G{$totalRow}:H{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("G{$totalRow}:H{$totalRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // ── Signature section ─────────────────────────────────────────
                $signRow = $totalRow + 2;
                $today   = now()->format('d/m/Y');
                $sheet->mergeCells("F{$signRow}:H{$signRow}");
                $sheet->setCellValue("F{$signRow}", "Ngày {$today}");
                $sheet->getStyle("F{$signRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("F{$signRow}")->getFont()->setItalic(true)->setSize(9);

                $signRow2 = $signRow + 1;
                $sheet->setCellValue("A{$signRow2}", 'Người lập biểu');
                $sheet->setCellValue("D{$signRow2}", 'Kế toán trưởng');
                $sheet->setCellValue("G{$signRow2}", 'Giám đốc');
                foreach (['A', 'D', 'G'] as $col) {
                    $sheet->getStyle("{$col}{$signRow2}")->applyFromArray([
                        'font'      => ['bold' => true, 'size' => 9],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                }
                $noteRow = $signRow2 + 1;
                foreach (['A', 'D', 'G'] as $col) {
                    $sheet->setCellValue("{$col}{$noteRow}", '(Ký, họ tên)');
                    $sheet->getStyle("{$col}{$noteRow}")->getFont()->setItalic(true)->setSize(8);
                    $sheet->getStyle("{$col}{$noteRow}")->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                // Row heights
                $sheet->getRowDimension($headerRow1)->setRowHeight(18);
                $sheet->getRowDimension($headerRow2)->setRowHeight(22);
            },
        ];
    }
}
