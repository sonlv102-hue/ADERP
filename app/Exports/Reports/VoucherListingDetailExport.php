<?php

namespace App\Exports\Reports;

use App\Http\Controllers\Reports\DocumentChecklistDetailController;
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

class VoucherListingDetailExport implements FromCollection, WithTitle, WithColumnWidths, WithEvents
{
    private array $rows;
    private array $totals;
    private array $filters;
    private array $company;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
        $ctrl          = new DocumentChecklistDetailController();
        [$this->rows, $this->totals] = $ctrl->buildReport($filters);
        $this->company = Setting::getGroup('company');
    }

    public function title(): string
    {
        return 'Bảng kê chứng từ chi tiết';
    }

    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6,  // STT
            'B' => 12, // Ngày CT
            'C' => 16, // Số CT
            'D' => 24, // Tên khách / Đối tượng
            'E' => 36, // Diễn giải
            'F' => 10, // Tài khoản
            'G' => 26, // Tên tài khoản
            'H' => 12, // TK đối ứng
            'I' => 16, // Phát sinh Nợ
            'J' => 16, // Phát sinh Có
            'K' => 12, // Nguồn
            'L' => 20, // Dự án
            'M' => 14, // Trạng thái
            'N' => 18, // Người tạo
            'O' => 16, // Thời gian hạch toán
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet   = $event->sheet->getDelegate();
                $rows    = $this->rows;
                $company = $this->company;
                $filters = $this->filters;

                // ── Header rows ─────────────────────────────────────────────
                $sheet->insertNewRowBefore(1, 6);

                $sheet->setCellValue('A1', $company['name'] ?? '');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(10);

                $sheet->setCellValue('A2', $company['address'] ?? '');
                $sheet->getStyle('A2')->getFont()->setSize(9);

                $sheet->mergeCells('A4:O4');
                $sheet->setCellValue('A4', 'BẢNG KÊ CHỨNG TỪ CHI TIẾT');
                $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $dateLabel = 'Từ ngày: ' . date('d/m/Y', strtotime($filters['date_from']))
                           . '   Đến ngày: ' . date('d/m/Y', strtotime($filters['date_to']));
                $sheet->mergeCells('A5:O5');
                $sheet->setCellValue('A5', $dateLabel);
                $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('A5')->getFont()->setItalic(true)->setSize(9);

                // ── Column headers (row 7) ───────────────────────────────────
                $headerRow = 7;
                $sheet->insertNewRowBefore($headerRow, 1);

                $headers = [
                    'STT', 'Ngày CT', 'Số CT', 'Tên khách / Đối tượng', 'Diễn giải',
                    'Tài khoản', 'Tên tài khoản', 'TK đối ứng', 'Phát sinh Nợ', 'Phát sinh Có',
                    'Nguồn', 'Dự án', 'Trạng thái', 'Người tạo', 'Thời gian hạch toán',
                ];
                $cols = range('A', 'O');
                foreach ($headers as $i => $label) {
                    $cell = $cols[$i] . $headerRow;
                    $sheet->setCellValue($cell, $label);
                    $sheet->getStyle($cell)->applyFromArray([
                        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '93c5fd']]],
                    ]);
                }

                // ── Data rows ────────────────────────────────────────────────
                $dataStart = $headerRow + 1;
                foreach ($rows as $ri => $row) {
                    $r = $dataStart + $ri;
                    $sheet->setCellValue("A{$r}", $ri + 1);
                    $sheet->setCellValue("B{$r}", date('d/m/Y', strtotime($row['date'])));
                    $sheet->setCellValue("C{$r}", $row['je_code']);
                    $sheet->setCellValue("D{$r}", $row['object_name']);
                    $sheet->setCellValue("E{$r}", $row['description']);
                    $sheet->setCellValue("F{$r}", $row['account_code']);
                    $sheet->setCellValue("G{$r}", $row['account_name']);
                    $sheet->setCellValue("H{$r}", $row['counter_account']);
                    $sheet->setCellValue("I{$r}", $row['debit']  > 0 ? $row['debit']  : '');
                    $sheet->setCellValue("J{$r}", $row['credit'] > 0 ? $row['credit'] : '');
                    $sheet->setCellValue("K{$r}", $row['source_label']);
                    $sheet->setCellValue("L{$r}", $row['project_name']);
                    $sheet->setCellValue("M{$r}", $row['status']);
                    $sheet->setCellValue("N{$r}", $row['created_by_name']);
                    $sheet->setCellValue("O{$r}", $row['posted_at']);

                    $sheet->getStyle("A{$r}:O{$r}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'd1d5db']]],
                        'font'    => ['size' => 9],
                    ]);
                    $sheet->getStyle("I{$r}:J{$r}")->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle("A{$r}:C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("F{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("I{$r}:J{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    if ($ri % 2 === 1) {
                        $sheet->getStyle("A{$r}:O{$r}")->getFill()
                            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F9FAFB');
                    }
                }

                // ── Total row ────────────────────────────────────────────────
                $totalRow = $dataStart + count($rows);
                $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
                $sheet->setCellValue("A{$totalRow}", 'TỔNG CỘNG');
                $sheet->setCellValue("I{$totalRow}", $this->totals['debit']);
                $sheet->setCellValue("J{$totalRow}", $this->totals['credit']);
                $sheet->getStyle("A{$totalRow}:O{$totalRow}")->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'EFF6FF']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                ]);
                $sheet->getStyle("I{$totalRow}:J{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("I{$totalRow}:J{$totalRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Chênh lệch row
                $diffRow = $totalRow + 1;
                $diff    = round($this->totals['debit'] - $this->totals['credit'], 2);
                $sheet->mergeCells("A{$diffRow}:H{$diffRow}");
                $sheet->setCellValue("A{$diffRow}", 'Chênh lệch');
                $sheet->mergeCells("I{$diffRow}:J{$diffRow}");
                $sheet->setCellValue("I{$diffRow}", $diff);
                $sheet->getStyle("A{$diffRow}:J{$diffRow}")->getFont()->setBold(true)
                    ->getColor()->setRGB($diff == 0 ? '166534' : 'B91C1C');

                // ── Signature section ─────────────────────────────────────────
                $signRow = $diffRow + 2;
                $today   = now()->format('d/m/Y');
                $sheet->mergeCells("K{$signRow}:O{$signRow}");
                $sheet->setCellValue("K{$signRow}", "Ngày {$today}");
                $sheet->getStyle("K{$signRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("K{$signRow}")->getFont()->setItalic(true)->setSize(9);

                $signRow2 = $signRow + 1;
                $sheet->setCellValue("A{$signRow2}", 'Người lập biểu');
                $sheet->setCellValue("F{$signRow2}", 'Kế toán trưởng');
                $sheet->setCellValue("K{$signRow2}", 'Giám đốc');
                foreach (['A', 'F', 'K'] as $col) {
                    $sheet->getStyle("{$col}{$signRow2}")->applyFromArray([
                        'font'      => ['bold' => true, 'size' => 9],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                }
                $noteRow = $signRow2 + 1;
                foreach (['A', 'F', 'K'] as $col) {
                    $sheet->setCellValue("{$col}{$noteRow}", '(Ký, họ tên)');
                    $sheet->getStyle("{$col}{$noteRow}")->getFont()->setItalic(true)->setSize(8);
                    $sheet->getStyle("{$col}{$noteRow}")->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $sheet->getRowDimension($headerRow)->setRowHeight(22);
            },
        ];
    }
}
