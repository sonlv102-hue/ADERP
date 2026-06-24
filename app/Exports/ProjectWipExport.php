<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\ProjectWipEntry;

class ProjectWipExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Chi phí dở dang TK154'; }
    protected function reportTitle(): string { return 'CHI PHÍ DỞ DANG TK 154 (WIP)'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($pid = $this->filters['project_id'] ?? null) {
            $name = Project::find($pid)?->code ?? "DA #{$pid}";
            $parts[] = "Dự án: {$name}";
        }
        if ($s = $this->filters['status'] ?? null) $parts[] = "Trạng thái: {$s}";
        else $parts[] = "Trạng thái: Đang hoạt động";
        return implode('   |   ', $parts);
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',      'header' => 'STT',            'width' => 5,  'type' => 'number'],
            ['key' => 'project',    'header' => 'Dự án',          'width' => 12, 'type' => 'text'],
            ['key' => 'entry_date', 'header' => 'Ngày',           'width' => 12, 'type' => 'date'],
            ['key' => 'cost_type',  'header' => 'Loại chi phí',   'width' => 20, 'type' => 'text'],
            ['key' => 'description','header' => 'Mô tả',          'width' => 40, 'type' => 'text'],
            ['key' => 'source_type','header' => 'Nguồn',          'width' => 18, 'type' => 'text'],
            ['key' => 'amount',     'header' => 'Số tiền (₫)',    'width' => 18, 'type' => 'money'],
            ['key' => 'vat_amount', 'header' => 'VAT (₫)',        'width' => 16, 'type' => 'money'],
            ['key' => 'status',     'header' => 'Trạng thái',     'width' => 14, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $projectId = $this->filters['project_id'] ?? null;
        $status    = $this->filters['status'] ?? 'active';

        return ProjectWipEntry::with(['project'])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($w) => [
                'project'     => $w->project?->code ?? '',
                'entry_date'  => $w->entry_date?->format('d/m/Y') ?? '',
                'cost_type'   => $w->costTypeLabel(),
                'description' => $w->description ?? '',
                'source_type' => $w->source_type ?? '',
                'amount'      => (float) $w->amount,
                'vat_amount'  => (float) ($w->vat_amount ?? 0),
                'status'      => $w->statusLabel(),
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        return [
            'project'    => 'Tổng cộng (' . count($rows) . ' mục)',
            'amount'     => array_sum(array_column($rows, 'amount')),
            'vat_amount' => array_sum(array_column($rows, 'vat_amount')),
        ];
    }
}
