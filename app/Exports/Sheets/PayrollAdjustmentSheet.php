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

class PayrollAdjustmentSheet implements FromArray, WithTitle, WithEvents, WithColumnFormatting, ShouldAutoSize
{
    public function __construct(
        private Payroll $payroll,
        private Collection $items,
    ) {}

    public function title(): string
    {
        return 'Điều chỉnh lương';
    }

    public function columnFormats(): array
    {
        return ['D' => '#,##0', 'E' => '#,##0', 'F' => '#,##0'];
    }

    public function array(): array
    {
        [$year, $month] = explode('-', $this->payroll->period);
        $rows = [];
        $rows[] = ["ĐIỀU CHỈNH LƯƠNG - THÁNG {$month}/{$year}"];
        $rows[] = ["Chỉ hiển thị nhân viên có số điều chỉnh khác 0"];
        $rows[] = [];
        $rows[] = ['STT', 'Mã NV', 'Họ và tên', 'Lương net (trước ĐC)', 'Điều chỉnh (+/-)', 'Thực lĩnh (sau ĐC)', 'Lý do điều chỉnh', 'Người điều chỉnh', 'Thời gian'];

        $adjusted = $this->items->filter(fn($i) => abs((float)$i['adjustment_amount']) > 0);

        if ($adjusted->isEmpty()) {
            $rows[] = ['', '', 'Không có điều chỉnh trong kỳ này.', '', '', '', '', '', ''];
            return $rows;
        }

        $seq = 1;
        foreach ($adjusted as $item) {
            $rows[] = [
                $seq++,
                $item['employee_code'] ?? '',
                $item['employee_name'] ?? '',
                (float)$item['net_salary'],
                (float)$item['adjustment_amount'],
                (float)$item['thuc_linh'],
                $item['adjustment_reason'] ?? '',
                $item['adjusted_by'] ?? '',
                $item['adjusted_at'] ?? '',
            ];
        }

        $rows[] = [];
        $rows[] = [
            '', '', 'TỔNG ĐIỀU CHỈNH',
            $adjusted->sum(fn($i) => (float)$i['net_salary']),
            $adjusted->sum(fn($i) => (float)$i['adjustment_amount']),
            $adjusted->sum(fn($i) => (float)$i['thuc_linh']),
            '', '', '',
        ];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:I1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->getStyle('A4:I4')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B45309']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
                ]);

                $lastRow = 4 + count($this->items->filter(fn($i) => abs((float)$i['adjustment_amount']) > 0)) + 2;
                $sheet->getStyle("A5:I{$lastRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                ]);
            },
        ];
    }
}
