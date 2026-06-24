<?php

namespace App\Exports;

use App\Models\Project;
use App\Models\ProjectExpense;

class ProjectExpensesExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Chi phí phát sinh dự án'; }
    protected function reportTitle(): string { return 'CHI PHÍ PHÁT SINH DỰ ÁN'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($pid = $this->filters['project_id'] ?? null) {
            $name = Project::find($pid)?->code ?? "DA #{$pid}";
            $parts[] = "Dự án: {$name}";
        }
        if ($s = $this->filters['status'] ?? null) $parts[] = "Trạng thái: {$s}";
        return $parts ? implode('   |   ', $parts) : 'Tất cả chi phí phát sinh';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',         'header' => 'STT',              'width' => 5,  'type' => 'number'],
            ['key' => 'project',       'header' => 'Dự án',            'width' => 12, 'type' => 'text'],
            ['key' => 'expense_date',  'header' => 'Ngày',             'width' => 12, 'type' => 'date'],
            ['key' => 'category',      'header' => 'Danh mục',         'width' => 20, 'type' => 'text'],
            ['key' => 'description',   'header' => 'Mô tả',            'width' => 40, 'type' => 'text'],
            ['key' => 'payment_method','header' => 'Hình thức',        'width' => 18, 'type' => 'text'],
            ['key' => 'amount',        'header' => 'Số tiền (₫)',      'width' => 18, 'type' => 'money'],
            ['key' => 'vat_amount',    'header' => 'VAT (₫)',          'width' => 16, 'type' => 'money'],
            ['key' => 'total',         'header' => 'Tổng cộng (₫)',   'width' => 18, 'type' => 'money'],
            ['key' => 'transfer_status','header' => 'Trạng thái KC',  'width' => 16, 'type' => 'text'],
            ['key' => 'status',        'header' => 'Trạng thái HT',   'width' => 16, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $projectId = $this->filters['project_id'] ?? null;
        $status    = $this->filters['status'] ?? null;

        return ProjectExpense::with(['project'])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($status,    fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($e) => [
                'project'        => $e->project?->code ?? '',
                'expense_date'   => $e->expense_date?->format('d/m/Y') ?? '',
                'category'       => $e->category?->label() ?? (string) $e->category,
                'description'    => $e->description ?? '',
                'payment_method' => $e->payment_method ?? '',
                'amount'         => (float) $e->amount,
                'vat_amount'     => (int) ($e->vat_amount ?? 0),
                'total'          => $e->totalAmount(),
                'transfer_status'=> $e->transfer_status ?? 'not_transferred',
                'status'         => $e->status ?? '',
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        return [
            'project'    => 'Tổng cộng (' . count($rows) . ' chi phí)',
            'amount'     => array_sum(array_column($rows, 'amount')),
            'vat_amount' => array_sum(array_column($rows, 'vat_amount')),
            'total'      => array_sum(array_column($rows, 'total')),
        ];
    }
}
