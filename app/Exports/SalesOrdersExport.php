<?php

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Models\Order;

class SalesOrdersExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách đơn hàng bán'; }
    protected function reportTitle(): string { return 'DANH SÁCH ĐƠN HÀNG BÁN'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($q = $this->filters['q'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        if ($s = $this->filters['status'] ?? null) {
            $label = OrderStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả đơn hàng bán';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',      'header' => 'STT',           'width' => 5,  'type' => 'number'],
            ['key' => 'code',       'header' => 'Mã ĐH',         'width' => 12, 'type' => 'text'],
            ['key' => 'order_date', 'header' => 'Ngày đặt',      'width' => 12, 'type' => 'date'],
            ['key' => 'customer',   'header' => 'Khách hàng',    'width' => 30, 'type' => 'text'],
            ['key' => 'total',      'header' => 'Tổng tiền (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'status',     'header' => 'Trạng thái',    'width' => 16, 'type' => 'text'],
            ['key' => 'creator',    'header' => 'Người lập',     'width' => 20, 'type' => 'text'],
            ['key' => 'notes',      'header' => 'Ghi chú',       'width' => 30, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $q      = $this->filters['q']      ?? null;
        $status = $this->filters['status'] ?? null;

        return Order::with(['customer', 'creator', 'items'])
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('notes', 'ilike', "%{$q}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($o) => [
                'code'       => $o->code,
                'order_date' => $o->order_date?->format('d/m/Y') ?? '',
                'customer'   => $o->customer->name,
                'total'      => $o->total(),
                'status'     => $o->status->label(),
                'creator'    => $o->creator->name,
                'notes'      => $o->notes ?? '',
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'total'));
        return [
            'code'  => 'Tổng cộng (' . count($rows) . ' đơn hàng)',
            'total' => $sum,
        ];
    }
}
