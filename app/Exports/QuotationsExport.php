<?php

namespace App\Exports;

use App\Enums\QuotationStatus;
use App\Models\Quotation;

class QuotationsExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách báo giá'; }
    protected function reportTitle(): string { return 'DANH SÁCH BÁO GIÁ'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($q = $this->filters['q'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        if ($s = $this->filters['status'] ?? null) {
            $label = QuotationStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả báo giá';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',           'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Mã BG',         'width' => 12, 'type' => 'text'],
            ['key' => 'created_at',  'header' => 'Ngày tạo',      'width' => 12, 'type' => 'date'],
            ['key' => 'customer',    'header' => 'Khách hàng',    'width' => 30, 'type' => 'text'],
            ['key' => 'valid_until', 'header' => 'Hiệu lực đến',  'width' => 14, 'type' => 'date'],
            ['key' => 'total',       'header' => 'Tổng tiền (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'status',      'header' => 'Trạng thái',    'width' => 14, 'type' => 'text'],
            ['key' => 'creator',     'header' => 'Người lập',     'width' => 20, 'type' => 'text'],
            ['key' => 'notes',       'header' => 'Ghi chú',       'width' => 30, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $q      = $this->filters['q']      ?? null;
        $status = $this->filters['status'] ?? null;

        return Quotation::with(['customer', 'creator', 'items'])
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('notes', 'ilike', "%{$q}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%")
                                                         ->orWhere('code', 'ilike', "%{$q}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($qt) => [
                'code'        => $qt->code,
                'created_at'  => $qt->created_at->format('d/m/Y'),
                'customer'    => $qt->customer->name,
                'valid_until' => $qt->valid_until?->format('d/m/Y') ?? '',
                'total'       => $qt->total(),
                'status'      => $qt->status->label(),
                'creator'     => $qt->creator->name,
                'notes'       => $qt->notes ?? '',
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'total'));
        return [
            'code'   => 'Tổng cộng (' . count($rows) . ' báo giá)',
            'total'  => $sum,
        ];
    }
}
