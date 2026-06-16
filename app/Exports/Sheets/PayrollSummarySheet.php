<?php

namespace App\Exports\Sheets;

use App\Models\Payroll;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PayrollSummarySheet implements FromArray, WithTitle, WithEvents, WithColumnFormatting, ShouldAutoSize
{
    public function __construct(
        private Payroll $payroll,
        private Collection $items,
    ) {}

    public function title(): string
    {
        return 'Bảng lương tổng hợp';
    }

    public function columnFormats(): array
    {
        // Columns L-U: số tiền VND
        $vnd = '#,##0';
        return [
            'L' => $vnd, 'M' => $vnd, 'N' => $vnd, 'O' => $vnd,
            'P' => $vnd, 'Q' => $vnd, 'R' => $vnd,
            'S' => $vnd, 'T' => $vnd, 'U' => $vnd,
            'V' => $vnd, 'W' => $vnd,
        ];
    }

    public function array(): array
    {
        $company = \App\Models\Setting::getGroup('company');
        $period  = $this->payroll->period;
        [$year, $month] = explode('-', $period);

        $rows = [];

        // Row 1: Company name
        $rows[] = [$company['company_name'] ?? 'CÔNG TY'];
        // Row 2: Address
        $rows[] = [$company['company_address'] ?? ''];
        // Row 3: blank
        $rows[] = [];
        // Row 4: Title
        $rows[] = ['BẢNG TÍNH - THANH TOÁN TIỀN LƯƠNG'];
        // Row 5: Period
        $rows[] = ["Tháng {$month} năm {$year}  ·  Mã: {$this->payroll->code}"];
        // Row 6: blank
        $rows[] = [];

        // Row 7: Group headers (merged in AfterSheet)
        $rows[] = [
            'STT', 'Mã NV', 'Họ và tên', 'Bộ phận', 'Chức vụ', 'Loại HĐ',   // A-F (6 cột nhân viên)
            'Công chuẩn', 'Công TT', 'Công hưởng lương', 'Nghỉ phép', 'Nghỉ KL', // G-K (5 cột công)
            'Lương CB', 'Lương theo công', 'Phụ cấp', 'Thưởng', 'Điều chỉnh', 'Tổng TN', // L-Q (6 cột thu nhập)
            'BHXH NV', 'BHYT NV', 'BHTN NV', 'Thuế TNCN', 'Tạm ứng', 'Tổng KT', // R-W (6 cột khấu trừ)
            'Thực lĩnh', 'Ghi chú lý do ĐC', 'Ký nhận', // X-Z
        ];

        // Data rows
        $seq = 1;
        foreach ($this->items as $item) {
            $totalIncome = (float)$item['gross_salary'] + (float)$item['adjustment_amount'];
            $totalDeduct = (float)$item['bhxh_employee'] + (float)$item['bhyt_employee']
                         + (float)$item['bhtn_employee'] + (float)$item['pit']
                         + (float)$item['advance'];
            $rows[] = [
                $seq++,
                $item['employee_code'] ?? '',
                $item['employee_name'] ?? '',
                $item['department'] ?? '',
                $item['position'] ?? '',
                $item['employment_type'] ?? '',
                $item['standard_days'],
                $item['actual_working_days'] ?? 0,
                $item['working_days'],
                $item['paid_leave_days'] ?? 0,
                $item['unpaid_leave_days'] ?? 0,
                (float)$item['base_salary'],
                (float)$item['gross_salary'],
                (float)$item['allowance'] + (float)$item['allowance_responsibility']
                    + (float)$item['allowance_lunch'] + (float)$item['allowance_phone']
                    + (float)$item['allowance_transport'] + (float)$item['allowance_performance'],
                (float)$item['bonus'],
                (float)$item['adjustment_amount'],
                $totalIncome,
                (float)$item['bhxh_employee'],
                (float)$item['bhyt_employee'],
                (float)$item['bhtn_employee'],
                (float)$item['pit'],
                (float)$item['advance'],
                $totalDeduct,
                (float)$item['thuc_linh'],
                $item['adjustment_reason'] ?? '',
                '',
            ];
        }

        // Total row
        $count = count($this->items);
        if ($count > 0) {
            $rows[] = [
                'TỔNG CỘNG', '', '', '', '', '',
                '', '', '', '', '',
                $this->items->sum(fn($i) => (float)$i['base_salary']),
                $this->items->sum(fn($i) => (float)$i['gross_salary']),
                $this->items->sum(fn($i) => (float)$i['allowance'] + (float)$i['allowance_responsibility']
                    + (float)$i['allowance_lunch'] + (float)$i['allowance_phone']
                    + (float)$i['allowance_transport'] + (float)$i['allowance_performance']),
                $this->items->sum(fn($i) => (float)$i['bonus']),
                $this->items->sum(fn($i) => (float)$i['adjustment_amount']),
                $this->items->sum(fn($i) => (float)$i['gross_salary'] + (float)$i['adjustment_amount']),
                $this->items->sum(fn($i) => (float)$i['bhxh_employee']),
                $this->items->sum(fn($i) => (float)$i['bhyt_employee']),
                $this->items->sum(fn($i) => (float)$i['bhtn_employee']),
                $this->items->sum(fn($i) => (float)$i['pit']),
                $this->items->sum(fn($i) => (float)$i['advance']),
                $this->items->sum(fn($i) => (float)$i['bhxh_employee'] + (float)$i['bhyt_employee']
                    + (float)$i['bhtn_employee'] + (float)$i['pit'] + (float)$i['advance']),
                $this->items->sum(fn($i) => (float)$i['thuc_linh']),
                '',
                '',
            ];
        }

        // Signature rows
        $rows[] = [];
        $today = now()->format('d/m/Y');
        $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', "Ngày xuất: {$today}"];
        $rows[] = [];
        $rows[] = ['', '', '', '', 'Người lập bảng', '', '', '', '', '', 'Kế toán trưởng', '', '', '', '', '', '', '', '', '', 'Giám đốc', '', '', ''];
        $rows[] = [];
        $rows[] = ['', '', '', '', '(Ký, họ tên)', '', '', '', '', '', '(Ký, họ tên)', '', '', '', '', '', '', '', '', '', '(Ký, họ tên)', '', '', ''];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastDataRow = 7 + count($this->items) + 1;

                // Title styling
                $sheet->mergeCells('A4:Z4');
                $sheet->getStyle('A4')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->mergeCells('A5:Z5');
                $sheet->getStyle('A5')->applyFromArray([
                    'font'      => ['size' => 11],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                // Header row 7 styling
                $sheet->getStyle('A7:Z7')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
                ]);

                // Data rows border
                if ($lastDataRow > 7) {
                    $sheet->getStyle("A8:Z{$lastDataRow}")->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                        'alignment' => ['wrapText' => true],
                    ]);

                    // Total row
                    $sheet->getStyle("A{$lastDataRow}:Z{$lastDataRow}")->applyFromArray([
                        'font' => ['bold' => true],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
                    ]);
                }

                // Freeze header
                $sheet->freezePane('C8');

                // Row heights
                $sheet->getRowDimension(7)->setRowHeight(35);

                // Company row
                $sheet->mergeCells('A1:Z1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 13],
                ]);
            },
        ];
    }
}
