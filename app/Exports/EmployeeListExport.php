<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Support\Collection;

class EmployeeListExport extends BaseListExport
{
    public function __construct(private Collection $employees, private array $filters) {}

    public function title(): string
    {
        return 'Danh sách nhân viên';
    }

    protected function reportTitle(): string
    {
        return 'DANH SÁCH CÁN BỘ CÔNG NHÂN VIÊN';
    }

    protected function filterDescription(): string
    {
        $parts = [];
        if (!empty($this->filters['q'])) {
            $parts[] = 'Từ khóa: "' . $this->filters['q'] . '"';
        }
        if (!empty($this->filters['status'])) {
            $parts[] = 'Trạng thái: ' . (\App\Enums\EmployeeStatus::from($this->filters['status'])->label());
        }
        return $parts ? implode(' | ', $parts) : 'Tất cả nhân viên';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',                  'header' => 'STT',              'width' => 6,  'type' => 'number'],
            ['key' => 'code',                   'header' => 'Mã nhân viên',      'width' => 12],
            ['key' => 'name',                   'header' => 'Họ và tên',         'width' => 22],
            ['key' => 'gender_label',            'header' => 'Giới tính',        'width' => 10],
            ['key' => 'birth_date',              'header' => 'Ngày sinh',        'width' => 12],
            ['key' => 'national_id',             'header' => 'CCCD/CMND',        'width' => 14],
            ['key' => 'national_id_issue_date',  'header' => 'Ngày cấp',         'width' => 12],
            ['key' => 'national_id_issue_place', 'header' => 'Nơi cấp',          'width' => 18],
            ['key' => 'phone',                   'header' => 'Số điện thoại',    'width' => 14],
            ['key' => 'email',                   'header' => 'Email',            'width' => 22],
            ['key' => 'address',                 'header' => 'Địa chỉ',          'width' => 22],
            ['key' => 'department',              'header' => 'Phòng ban',        'width' => 16],
            ['key' => 'position',                'header' => 'Chức vụ',          'width' => 16],
            ['key' => 'hire_date',                'header' => 'Ngày vào làm',    'width' => 12],
            ['key' => 'employment_type_label',    'header' => 'Loại hợp đồng',   'width' => 14],
            ['key' => 'contract_start_date',      'header' => 'Ngày BĐ hợp đồng', 'width' => 14],
            ['key' => 'contract_end_date',        'header' => 'Ngày KT hợp đồng', 'width' => 14],
            ['key' => 'base_salary',              'header' => 'Lương cơ bản',    'width' => 14, 'type' => 'money'],
            ['key' => 'allowance_total',          'header' => 'Phụ cấp',         'width' => 14, 'type' => 'money'],
            ['key' => 'pit_tax_code',             'header' => 'Mã số thuế cá nhân', 'width' => 14],
            ['key' => 'social_insurance_no',      'header' => 'Số BHXH',         'width' => 14],
            ['key' => 'bank_account_no',          'header' => 'Số tài khoản',    'width' => 16],
            ['key' => 'bank_name',                'header' => 'Ngân hàng',       'width' => 16],
            ['key' => 'status_label',             'header' => 'Trạng thái',      'width' => 12],
            ['key' => 'notes',                    'header' => 'Ghi chú',         'width' => 20],
        ];
    }

    protected function buildRows(): array
    {
        return $this->employees->map(fn (Employee $e) => [
            'code'                     => $e->code,
            'name'                     => $e->name,
            'gender_label'             => $e->gender === 'male' ? 'Nam' : ($e->gender === 'female' ? 'Nữ' : ''),
            'birth_date'               => $e->birth_date?->format('d/m/Y'),
            'national_id'              => $e->national_id,
            'national_id_issue_date'   => $e->national_id_issue_date?->format('d/m/Y'),
            'national_id_issue_place'  => $e->national_id_issue_place,
            'phone'                    => $e->phone,
            'email'                    => $e->email,
            'address'                  => $e->address,
            'department'               => $e->department,
            'position'                 => $e->position,
            'hire_date'                => $e->hire_date?->format('d/m/Y'),
            'employment_type_label'    => $e->employment_type->label(),
            'contract_start_date'      => $e->contract_start_date?->format('d/m/Y'),
            'contract_end_date'        => $e->contract_end_date?->format('d/m/Y'),
            'base_salary'              => (float) $e->base_salary,
            'allowance_total'          => (float) $e->totalAllowances(),
            'pit_tax_code'             => $e->pit_tax_code,
            'social_insurance_no'      => $e->social_insurance_no,
            'bank_account_no'          => $e->bank_account_no,
            'bank_name'                => $e->bank_name,
            'status_label'             => $e->status->label(),
            'notes'                    => $e->notes,
        ])->all();
    }
}
