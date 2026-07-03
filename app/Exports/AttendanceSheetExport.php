<?php

namespace App\Exports;

use App\Models\AttendanceSheet;
use Illuminate\Support\Collection;

class AttendanceSheetExport extends BaseListExport
{
    public function __construct(
        private AttendanceSheet $sheet,
        private Collection $records,
        private int $daysInMonth,
    ) {}

    public function title(): string
    {
        return 'Bảng chấm công ' . $this->sheet->period;
    }

    protected function reportTitle(): string
    {
        [$year, $month] = explode('-', $this->sheet->period);
        return 'BẢNG CHẤM CÔNG THÁNG ' . (int) $month . '/' . $year;
    }

    protected function filterDescription(): string
    {
        return 'Mã: ' . $this->sheet->code . ' | Trạng thái: ' . $this->sheet->status->label();
    }

    protected function columns(): array
    {
        $columns = [
            ['key' => '__stt',          'header' => 'STT',        'width' => 5,  'type' => 'number'],
            ['key' => 'employee_code',  'header' => 'Mã NV',      'width' => 10],
            ['key' => 'employee_name',  'header' => 'Họ và tên',  'width' => 22],
            ['key' => 'position',       'header' => 'Chức vụ',    'width' => 16],
            ['key' => 'department',     'header' => 'Phòng ban',  'width' => 16],
        ];

        for ($d = 1; $d <= $this->daysInMonth; $d++) {
            $columns[] = ['key' => "day_{$d}", 'header' => (string) $d, 'width' => 4];
        }

        $columns[] = ['key' => 'cong',             'header' => 'Công',     'width' => 8,  'type' => 'number'];
        $columns[] = ['key' => 'nghi_huong_luong', 'header' => 'Nghỉ HL',  'width' => 9,  'type' => 'number'];
        $columns[] = ['key' => 'nghi_khong_luong', 'header' => 'Nghỉ KL',  'width' => 9,  'type' => 'number'];
        $columns[] = ['key' => 'ot',               'header' => 'OT',       'width' => 6,  'type' => 'number'];
        $columns[] = ['key' => 'tong',             'header' => 'Tổng',     'width' => 8,  'type' => 'number'];

        return $columns;
    }

    protected function buildRows(): array
    {
        return $this->records->map(function ($r) {
            $row = [
                'employee_code' => $r['employee_code'],
                'employee_name' => $r['employee_name'],
                'position'      => $r['position'],
                'department'    => $r['department'],
            ];

            for ($d = 1; $d <= $this->daysInMonth; $d++) {
                $row["day_{$d}"] = $r['days'][$d] ?? '';
            }

            $row['cong']             = $r['cong'];
            $row['nghi_huong_luong'] = $r['nghi_huong_luong'];
            $row['nghi_khong_luong'] = $r['nghi_khong_luong'];
            $row['ot']               = $r['ot'];
            $row['tong']             = $r['tong'];

            return $row;
        })->all();
    }

    protected function buildTotals(): array
    {
        return [
            'cong'             => $this->records->sum('cong'),
            'nghi_huong_luong' => $this->records->sum('nghi_huong_luong'),
            'nghi_khong_luong' => $this->records->sum('nghi_khong_luong'),
            'ot'               => $this->records->sum('ot'),
            'tong'             => $this->records->sum('tong'),
        ];
    }
}
