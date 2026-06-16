<?php

namespace App\Exports\Sheets;

use App\Models\Payroll;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PayrollAttendanceSheet implements FromArray, WithTitle, WithEvents, ShouldAutoSize
{
    public function __construct(
        private Payroll $payroll,
        private Collection $items,
    ) {}

    public function title(): string
    {
        return 'Chi tiết công - giờ';
    }

    public function array(): array
    {
        [$year, $month] = explode('-', $this->payroll->period);
        $rows = [];
        $rows[] = ["CHI TIẾT CHUYÊN CẦN - THÁNG {$month}/{$year}"];
        $rows[] = [];
        $rows[] = [
            'STT', 'Mã NV', 'Họ và tên', 'Bộ phận', 'Chức vụ',
            'Công chuẩn', 'Công TT', 'Công hưởng lương',
            'Nghỉ phép hưởng lương', 'Nghỉ không lương',
            'OT ngày thường (giờ)', 'OT cuối tuần (giờ)', 'OT ngày lễ (giờ)',
            'Ghi chú',
        ];

        $seq = 1;
        foreach ($this->items as $item) {
            $rows[] = [
                $seq++,
                $item['employee_code'] ?? '',
                $item['employee_name'] ?? '',
                $item['department'] ?? '',
                $item['position'] ?? '',
                $item['standard_days'],
                $item['actual_working_days'] ?? 0,
                $item['working_days'],
                $item['paid_leave_days'] ?? 0,
                $item['unpaid_leave_days'] ?? 0,
                $item['overtime_days'] ?? 0,
                0,
                0,
                $item['attendance_note'] ?? '',
            ];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:N1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->getStyle('A3:N3')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '065F46']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
                ]);

                $lastRow = 3 + count($this->items);
                if ($lastRow > 3) {
                    $sheet->getStyle("A4:N{$lastRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                    ]);
                }

                $sheet->freezePane('C4');
                $sheet->getRowDimension(3)->setRowHeight(30);
            },
        ];
    }
}
