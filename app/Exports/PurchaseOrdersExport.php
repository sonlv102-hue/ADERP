<?php

namespace App\Exports;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

class PurchaseOrdersExport extends BaseListExport
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Danh sách đơn mua hàng'; }
    protected function reportTitle(): string { return 'DANH SÁCH ĐƠN MUA HÀNG'; }

    protected function filterDescription(): string
    {
        $parts = [];
        if ($q = $this->filters['q'] ?? null) $parts[] = "Tìm kiếm: {$q}";
        if ($s = $this->filters['status'] ?? null) {
            $label = PurchaseOrderStatus::tryFrom($s)?->label() ?? $s;
            $parts[] = "Trạng thái: {$label}";
        }
        return $parts ? implode('   |   ', $parts) : 'Tất cả đơn mua hàng';
    }

    protected function columns(): array
    {
        return [
            ['key' => '__stt',       'header' => 'STT',           'width' => 5,  'type' => 'number'],
            ['key' => 'code',        'header' => 'Mã ĐM',         'width' => 12, 'type' => 'text'],
            ['key' => 'order_date',  'header' => 'Ngày đặt',      'width' => 12, 'type' => 'date'],
            ['key' => 'supplier',    'header' => 'Nhà cung cấp',  'width' => 30, 'type' => 'text'],
            ['key' => 'warehouse',   'header' => 'Kho',           'width' => 20, 'type' => 'text'],
            ['key' => 'project',     'header' => 'Dự án',         'width' => 14, 'type' => 'text'],
            ['key' => 'total',       'header' => 'Tổng tiền (₫)', 'width' => 18, 'type' => 'money'],
            ['key' => 'status',      'header' => 'Trạng thái',    'width' => 16, 'type' => 'text'],
            ['key' => 'creator',     'header' => 'Người lập',     'width' => 20, 'type' => 'text'],
        ];
    }

    protected function buildRows(): array
    {
        $q      = $this->filters['q']      ?? null;
        $status = $this->filters['status'] ?? null;

        return PurchaseOrder::with(['supplier', 'warehouse', 'creator', 'project'])
            ->addSelect([
                'purchase_orders.*',
                'items_total' => PurchaseOrderItem::selectRaw(
                    'COALESCE(SUM(quantity * unit_price * (1 + COALESCE(vat_rate, 0) / 100.0)), 0)'
                )->whereColumn('purchase_order_id', 'purchase_orders.id'),
            ])
            ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                $sq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('notes', 'ilike', "%{$q}%")
                   ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$q}%")
                                                          ->orWhere('code', 'ilike', "%{$q}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn ($po) => [
                'code'       => $po->code,
                'order_date' => $po->order_date?->format('d/m/Y') ?? '',
                'supplier'   => $po->supplier->name,
                'warehouse'  => $po->warehouse->name,
                'project'    => $po->project?->code ?? '',
                'total'      => (float) $po->items_total,
                'status'     => $po->status->label(),
                'creator'    => $po->creator->name,
            ])
            ->toArray();
    }

    protected function buildTotals(): array
    {
        $rows = $this->buildRows();
        $sum  = array_sum(array_column($rows, 'total'));
        return [
            'code'  => 'Tổng cộng (' . count($rows) . ' đơn mua)',
            'total' => $sum,
        ];
    }
}
