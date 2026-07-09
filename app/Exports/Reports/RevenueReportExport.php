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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class RevenueReportExport implements FromArray, WithTitle, WithColumnWidths, WithEvents
{
    private int $headerRows = 7;   // Rows before table header
    private int $tableHeader = 8;  // Row number of column headers
    private int $dataStart  = 9;   // First data row

    public function __construct(
        private $invoices,
        private array $summary,
        private array $glReconcile,
        private string $periodLabel,
        private array $company
    ) {}

    public function title(): string
    {
        return 'Báo cáo doanh thu';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15, // Số chứng từ
            'B' => 35, // Khách hàng
            'C' => 15, // Ngày hóa đơn
            'D' => 20, // Doanh thu chưa VAT
            'E' => 18, // Thuế GTGT đầu ra
            'F' => 20, // Tổng thanh toán
            'G' => 15, // Trạng thái
        ];
    }

    public function array(): array
    {
        $rows = [];
        
        // 1. Header công ty
        $rows[] = [$this->company['company_name'] ?? 'Đơn vị báo cáo', '', '', '', '', '', ''];
        $rows[] = ['Địa chỉ: ' . ($this->company['company_address'] ?? ''), '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', ''];
        
        // 2. Tiêu đề báo cáo
        $rows[] = ['BÁO CÁO DOANH THU', '', '', '', '', '', ''];
        $rows[] = ['Kỳ báo cáo: ' . $this->periodLabel, '', '', '', '', '', ''];
        $rows[] = ['Ngày xuất: ' . now()->format('d/m/Y H:i'), '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', ''];

        // 3. Table Header (dòng 8)
        $rows[] = [
            'Số chứng từ',
            'Khách hàng',
            'Ngày hóa đơn',
            'Doanh thu chưa VAT',
            'Thuế GTGT đầu ra',
            'Tổng thanh toán',
            'Trạng thái'
        ];

        // 4. Data Rows
        foreach ($this->invoices as $invoice) {
            $statusLabel = match ($invoice->status) {
                'sent'     => 'Đã gửi',
                'paid'     => 'Đã thanh toán',
                'overdue'  => 'Quá hạn',
                'cancelled'=> 'Đã hủy',
                'draft'    => 'Nháp',
                default    => $invoice->status,
            };

            $rows[] = [
                $invoice->code,
                $invoice->customer_name ?? 'Khách lẻ',
                $invoice->issue_date ? \Carbon\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '',
                $invoice->subtotal,
                $invoice->tax_amount,
                $invoice->total,
                $statusLabel,
            ];
        }

        // 5. Total Row
        $rows[] = [
            'Tổng cộng',
            '',
            '',
            $this->summary['total_subtotal'],
            $this->summary['total_tax'],
            $this->summary['total_payment'],
            ''
        ];

        // 6. Reconciliation Section
        $rows[] = ['', '', '', '', '', '', ''];
        $rows[] = ['ĐỐI CHIẾU SỐ LIỆU VỚI SỔ CÁI KẾ TOÁN (BÚT TOÁN ĐÃ GHI SỔ)', '', '', '', '', '', ''];
        $rows[] = ['Chỉ tiêu', 'Số liệu hóa đơn', 'Số liệu Sổ cái (GL)', 'Chênh lệch', '', '', ''];
        $rows[] = [
            '1. Doanh thu (TK 511)',
            $this->summary['total_subtotal'],
            $this->glReconcile['gl_revenue'],
            $this->glReconcile['revenue_diff'],
            '',
            '',
            ''
        ];
        $rows[] = [
            '2. Thuế GTGT đầu ra (TK 3331)',
            $this->summary['total_tax'],
            $this->glReconcile['gl_vat'],
            $this->glReconcile['vat_diff'],
            '',
            '',
            ''
        ];

        // 7. Signature Block
        $rows[] = ['', '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', ''];
        $rows[] = ['Lập, ngày ' . now()->format('d') . ' tháng ' . now()->format('m') . ' năm ' . now()->format('Y'), '', '', '', '', '', ''];
        $rows[] = ['', '', '', '', '', '', ''];
        $rows[] = ['Người lập biểu', '', 'Kế toán trưởng', '', '', 'Người đại diện theo pháp luật', ''];
        $rows[] = ['(Ký, họ tên)', '', '(Ký, họ tên)', '', '', '(Ký, họ tên, đóng dấu)', ''];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $invoiceCount = count($this->invoices);
                $totalRowIdx = $this->dataStart + $invoiceCount;
                $reconHeaderIdx = $totalRowIdx + 2;
                $reconSubHeaderIdx = $reconHeaderIdx + 1;
                $reconRow1Idx = $reconSubHeaderIdx + 1;
                $reconRow2Idx = $reconRow1Idx + 1;
                $sigDateRowIdx = $reconRow2Idx + 3;
                $sigHeaderRowIdx = $sigDateRowIdx + 2;
                $sigDetailRowIdx = $sigHeaderRowIdx + 1;

                // Merge headers
                $sheet->mergeCells('A1:C1');
                $sheet->mergeCells('A2:C2');
                $sheet->mergeCells('A4:G4');
                $sheet->mergeCells('A5:G5');
                $sheet->mergeCells('A6:G6');

                // Styling Title
                $sheet->getStyle('A4')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle('A5')->applyFromArray([
                    'font'      => ['italic' => true, 'size' => 10],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle('A6')->applyFromArray([
                    'font'      => ['italic' => true, 'size' => 9],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Table Header styling
                $sheet->getStyle("A{$this->tableHeader}:G{$this->tableHeader}")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '93c5fd']]],
                ]);
                $sheet->getRowDimension($this->tableHeader)->setRowHeight(26);

                // Format data cells
                $baseBorder = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]]];
                for ($i = 0; $i < $invoiceCount; $i++) {
                    $rIdx = $this->dataStart + $i;
                    $sheet->getStyle("A{$rIdx}:G{$rIdx}")->applyFromArray($baseBorder);
                    $sheet->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("C{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("G{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("D{$rIdx}:F{$rIdx}")->getNumberFormat()->setFormatCode('#,##0');
                }

                // Format Total Row
                $sheet->mergeCells("A{$totalRowIdx}:C{$totalRowIdx}");
                $sheet->getStyle("A{$totalRowIdx}:G{$totalRowIdx}")->applyFromArray([
                    'font'    => ['bold' => true],
                    'fill'    => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'F0FDF4']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '93C5FD']]],
                ]);
                $sheet->getStyle("D{$totalRowIdx}:F{$totalRowIdx}")->getNumberFormat()->setFormatCode('#,##0');

                // Styling Reconciliation Section
                $sheet->mergeCells("A{$reconHeaderIdx}:G{$reconHeaderIdx}");
                $sheet->getStyle("A{$reconHeaderIdx}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '1E3A5F']],
                ]);

                // Reconciliation Table Border & styling
                $sheet->mergeCells("D{$reconSubHeaderIdx}:G{$reconSubHeaderIdx}");
                $sheet->mergeCells("D{$reconRow1Idx}:G{$reconRow1Idx}");
                $sheet->mergeCells("D{$reconRow2Idx}:G{$reconRow2Idx}");
                
                $reconTableRange = "A{$reconSubHeaderIdx}:G{$reconRow2Idx}";
                $sheet->getStyle($reconTableRange)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0BEC5']]],
                ]);
                $sheet->getStyle("A{$reconSubHeaderIdx}:G{$reconSubHeaderIdx}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'ECEFF1']],
                ]);
                
                $sheet->getStyle("B{$reconRow1Idx}:D{$reconRow2Idx}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("D{$reconRow1Idx}:D{$reconRow2Idx}")->applyFromArray([
                    'font' => ['bold' => true],
                ]);

                // Format Signatures
                $sheet->mergeCells("A{$sigDateRowIdx}:G{$sigDateRowIdx}");
                $sheet->getStyle("A{$sigDateRowIdx}")->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                    'font'      => ['italic' => true],
                ]);

                $sheet->getStyle("A{$sigHeaderRowIdx}:G{$sigDetailRowIdx}")->applyFromArray([
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle("A{$sigHeaderRowIdx}:G{$sigHeaderRowIdx}")->applyFromArray(['font' => ['bold' => true]]);
                $sheet->getStyle("A{$sigDetailRowIdx}:G{$sigDetailRowIdx}")->applyFromArray(['font' => ['italic' => true, 'size' => 9]]);
                
                $sheet->mergeCells("A{$sigHeaderRowIdx}:B{$sigHeaderRowIdx}");
                $sheet->mergeCells("A{$sigDetailRowIdx}:B{$sigDetailRowIdx}");
                $sheet->mergeCells("C{$sigHeaderRowIdx}:E{$sigHeaderRowIdx}");
                $sheet->mergeCells("C{$sigDetailRowIdx}:E{$sigDetailRowIdx}");
                $sheet->mergeCells("F{$sigHeaderRowIdx}:G{$sigHeaderRowIdx}");
                $sheet->mergeCells("F{$sigDetailRowIdx}:G{$sigDetailRowIdx}");
            },
        ];
    }
}
