<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class EmployeeImportErrorExport implements FromArray, WithHeadings, WithTitle
{
    /** @param array<int, array{row:int, data:array, errors:string[]}> $rows */
    public function __construct(private array $rows) {}

    public function title(): string
    {
        return 'Lỗi import';
    }

    public function headings(): array
    {
        return [
            'Dòng', 'Mã nhân viên', 'Họ và tên', 'Phòng ban', 'Chức vụ', 'Lỗi',
        ];
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->rows as $entry) {
            if (empty($entry['errors'])) {
                continue;
            }
            $out[] = [
                $entry['row'],
                $entry['data']['code'] ?? '',
                $entry['data']['name'] ?? '',
                $entry['data']['department'] ?? '',
                $entry['data']['position'] ?? '',
                implode(' | ', $entry['errors']),
            ];
        }
        return $out;
    }
}
