<?php

namespace App\Exports;

use App\Models\SmallTool;
use Illuminate\Support\Collection;

class SmallToolListExport extends BaseListExport
{
    public function __construct(private Collection $tools, private array $filters) {}

    public function title(): string
    {
        return 'Danh sách CCDC';
    }

    protected function reportTitle(): string
    {
        return 'DANH SÁCH CÔNG CỤ DỤNG CỤ';
    }

    protected function filterDescription(): string
    {
        $parts = [];
        if (!empty($this->filters['search'])) {
            $parts[] = 'Từ khóa: "' . $this->filters['search'] . '"';
        }
        if (!empty($this->filters['status'])) {
            $parts[] = 'Trạng thái: ' . $this->filters['status'];
        }
        if (!empty($this->filters['department'])) {
            $parts[] = 'Bộ phận: ' . $this->filters['department'];
        }
        return $parts ? implode(' | ', $parts) : 'Tất cả CCDC';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',             'header' => 'STT',              'width' => 6,  'type' => 'number'],
            ['key' => 'code',              'header' => 'Mã CCDC',          'width' => 12],
            ['key' => 'name',              'header' => 'Tên CCDC',         'width' => 24],
            ['key' => 'category_name',     'header' => 'Nhóm',             'width' => 16],
            ['key' => 'unit',              'header' => 'ĐVT',              'width' => 8],
            ['key' => 'quantity',          'header' => 'Số lượng',         'width' => 8,  'type' => 'number'],
            ['key' => 'original_cost',     'header' => 'Nguyên giá',       'width' => 14, 'type' => 'money'],
            ['key' => 'vat_amount',        'header' => 'VAT',              'width' => 12, 'type' => 'money'],
            ['key' => 'total_cost',        'header' => 'Tổng tiền',        'width' => 14, 'type' => 'money'],
            ['key' => 'acquisition_label', 'header' => 'Luồng nghiệp vụ',  'width' => 14],
            ['key' => 'recognition_label', 'header' => 'Ghi nhận chi phí', 'width' => 14],
            ['key' => 'status_label',      'header' => 'Trạng thái',       'width' => 14],
            ['key' => 'department',        'header' => 'Bộ phận',          'width' => 14],
            ['key' => 'employee_name',     'header' => 'Nhân viên',        'width' => 16],
            ['key' => 'warehouse_name',    'header' => 'Kho',              'width' => 14],
            ['key' => 'project_name',      'header' => 'Dự án',            'width' => 16],
            ['key' => 'supplier_name',     'header' => 'Nhà cung cấp',     'width' => 18],
            ['key' => 'total_allocated',   'header' => 'Đã phân bổ',       'width' => 14, 'type' => 'money'],
            ['key' => 'total_remaining',   'header' => 'Còn lại',          'width' => 14, 'type' => 'money'],
            ['key' => 'notes',             'header' => 'Ghi chú',          'width' => 20],
        ];
    }

    protected function buildRows(): array
    {
        return $this->tools->map(fn (SmallTool $t) => [
            'code'              => $t->code,
            'name'              => $t->name,
            'category_name'     => $t->category?->name,
            'unit'              => $t->unit,
            'quantity'          => $t->quantity,
            'original_cost'     => (float) $t->original_cost,
            'vat_amount'        => (float) $t->vat_amount,
            'total_cost'        => (float) $t->total_cost,
            'acquisition_label' => $t->acquisition_type === 'direct' ? 'Dùng ngay' : 'Nhập kho',
            'recognition_label' => $t->recognition_method === 'allocation' ? 'Phân bổ nhiều kỳ' : 'Chi phí một lần',
            'status_label'      => $t->status->label(),
            'department'        => $t->department,
            'employee_name'     => $t->responsibleEmployee?->name,
            'warehouse_name'    => $t->warehouse?->name,
            'project_name'      => $t->project?->name,
            'supplier_name'     => $t->supplier?->name,
            'total_allocated'   => (float) $t->total_allocated,
            'total_remaining'   => (float) $t->total_remaining,
            'notes'             => $t->notes,
        ])->all();
    }
}
