<?php

namespace App\Exports;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class EmployeeImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new EmployeeImportListSheet(),
            new EmployeeImportGuideSheet(),
            new EmployeeImportReferenceSheet(),
        ];
    }
}

class EmployeeImportListSheet implements FromArray, WithHeadings, WithTitle
{
    public function title(): string { return 'Danh sách nhân viên'; }

    public function headings(): array
    {
        return [
            'Mã nhân viên *', 'Họ và tên *', 'Giới tính', 'Ngày sinh', 'CCCD/CMND',
            'Ngày cấp', 'Nơi cấp', 'Số điện thoại', 'Email', 'Địa chỉ',
            'Phòng ban *', 'Chức vụ', 'Ngày vào làm', 'Loại hợp đồng', 'Trạng thái',
            'Lương cơ bản', 'Phụ cấp', 'Mã số thuế cá nhân', 'Số BHXH', 'Số tài khoản',
            'Ngân hàng', 'Ghi chú',
        ];
    }

    public function array(): array
    {
        return [[
            'NV-0001', 'Nguyễn Văn A', 'Nam', '01/01/1995', '001095012345',
            '10/05/2020', 'Công an TP Hà Nội', '0912345678', 'nva@example.com', 'Hà Nội',
            'Kỹ thuật', 'Nhân viên', '01/06/2023', 'Toàn thời gian', 'Đang làm',
            '10000000', '1000000', '0123456789', '0123456789', '0011002233445',
            'Vietcombank', '',
        ]];
    }
}

class EmployeeImportGuideSheet implements FromArray, WithTitle
{
    public function title(): string { return 'Hướng dẫn nhập liệu'; }

    public function array(): array
    {
        return [
            ['HƯỚNG DẪN NHẬP LIỆU'],
            [''],
            ['Cột bắt buộc (*): Mã nhân viên, Họ và tên, Phòng ban.'],
            ['Định dạng ngày: dd/mm/yyyy hoặc yyyy-mm-dd (ví dụ 01/01/1995 hoặc 1995-01-01).'],
            ['Giới tính hợp lệ: Nam / Nữ (hoặc male / female).'],
            ['Loại hợp đồng hợp lệ: ' . implode(', ', array_map(fn ($c) => $c->label(), EmploymentType::cases())) . '.'],
            ['Trạng thái hợp lệ: ' . implode(', ', array_map(fn ($c) => $c->label(), EmployeeStatus::cases())) . '.'],
            ['Lương cơ bản, Phụ cấp: nhập số, không nhập ký tự tiền tệ hoặc dấu phẩy.'],
            ['Lưu ý: Mã nhân viên không được trùng nhau trong file và không được trùng với nhân viên đã có trong hệ thống'],
            ['(trừ khi bật tùy chọn "Cho phép cập nhật nhân viên đã tồn tại" khi import).'],
        ];
    }
}

class EmployeeImportReferenceSheet implements FromArray, WithHeadings, WithTitle
{
    public function title(): string { return 'Danh mục tham chiếu'; }

    public function headings(): array
    {
        return ['Phòng ban hiện có', 'Chức vụ hiện có', 'Loại hợp đồng', 'Trạng thái'];
    }

    public function array(): array
    {
        $departments = Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')->values();
        $positions   = Employee::query()->whereNotNull('position')->distinct()->orderBy('position')->pluck('position')->values();
        $types       = collect(EmploymentType::cases())->map(fn ($c) => $c->label())->values();
        $statuses    = collect(EmployeeStatus::cases())->map(fn ($c) => $c->label())->values();

        $max = max($departments->count(), $positions->count(), $types->count(), $statuses->count());
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                $departments->get($i, ''),
                $positions->get($i, ''),
                $types->get($i, ''),
                $statuses->get($i, ''),
            ];
        }
        return $rows;
    }
}
