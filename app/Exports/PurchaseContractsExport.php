<?php

namespace App\Exports;

use App\Enums\PurchaseContractStatus;
use App\Models\PurchaseContract;

class PurchaseContractsExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách hợp đồng mua'; }
    protected function reportTitle(): string { return 'DANH SÁCH HỢP ĐỒNG MUA'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($q = $this->filters['q'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        if ($s = $this->filters['status'] ?? null) {
            $label = PurchaseContractStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả hợp đồng mua';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',              'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Số HĐ',            'width' => 12, 'type' => 'text'],
            ['key' => 'title',       'header' => 'Tiêu đề',          'width' => 35, 'type' => 'text'],
            ['key' => 'supplier',    'header' => 'Nhà cung cấp',     'width' => 30, 'type' => 'text'],
            ['key' => 'value',       'header' => 'Giá trị HĐ (₫)',  'width' => 20, 'type' => 'money'],
            ['key' => 'start_date',  'header' => 'Ngày bắt đầu',    'width' => 14, 'type' => 'date'],
            ['key' => 'end_date',    'header' => 'Ngày kết thúc',   'width' => 14, 'type' => 'date'],
            ['key' => 'status',      'header' => 'Trạng thái',       'width' => 14, 'type' => 'text'],
            ['key' => 'creator',     'header' => 'Người lập',        'width' => 20, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $q      = $this->filters['q']      ?? null;
        $status = $this->filters['status'] ?? null;

        return PurchaseContract::with(['supplier', 'creator'])
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('title', 'ilike', "%{$q}%")
                   ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$q}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'code'       => $c->code,
                'title'      => $c->title ?? '',
                'supplier'   => $c->supplier->name,
                'value'      => (float) $c->value,
                'start_date' => $c->start_date?->format('d/m/Y') ?? '',
                'end_date'   => $c->end_date?->format('d/m/Y') ?? '',
                'status'     => $c->status->label(),
                'creator'    => $c->creator->name,
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'value'));
        return [
            'code'  => 'Tổng cộng (' . count($rows) . ' hợp đồng)',
            'value' => $sum,
        ];
    }
}
