<?php

namespace App\Exports;

use App\Enums\ProjectStatus;
use App\Models\Project;

class ProjectsExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách dự án'; }
    protected function reportTitle(): string { return 'DANH SÁCH DỰ ÁN'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($s = $this->filters['status'] ?? null) {
            $label = ProjectStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        if ($q = $this->filters['q'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        return $parts ? implode('   |   ', $parts) : 'Tất cả dự án';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',            'header' => 'STT',              'width' => 5,  'type' => 'number'],
            ['key' => 'code',             'header' => 'Mã DA',            'width' => 12, 'type' => 'text'],
            ['key' => 'name',             'header' => 'Tên dự án',        'width' => 35, 'type' => 'text'],
            ['key' => 'customer',         'header' => 'Khách hàng',       'width' => 28, 'type' => 'text'],
            ['key' => 'budget',           'header' => 'Ngân sách (₫)',    'width' => 18, 'type' => 'money'],
            ['key' => 'start_date',       'header' => 'Ngày bắt đầu',    'width' => 14, 'type' => 'date'],
            ['key' => 'expected_end_date','header' => 'Ngày KT dự kiến', 'width' => 16, 'type' => 'date'],
            ['key' => 'status',           'header' => 'Trạng thái',       'width' => 16, 'type' => 'text'],
            ['key' => 'manager',          'header' => 'Người phụ trách',  'width' => 22, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $status = $this->filters['status'] ?? null;
        $q      = $this->filters['q'] ?? null;

        return Project::with(['customer', 'manager'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('name', 'ilike', "%{$q}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%"));
            }))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => [
                'code'              => $p->code,
                'name'              => $p->name,
                'customer'          => $p->customer?->name ?? '',
                'budget'            => (float) ($p->budget ?? 0),
                'start_date'        => $p->start_date?->format('d/m/Y') ?? '',
                'expected_end_date' => $p->expected_end_date?->format('d/m/Y') ?? '',
                'status'            => $p->status->label(),
                'manager'           => $p->manager?->name ?? '',
            ])
            ->toArray();
    }
}
