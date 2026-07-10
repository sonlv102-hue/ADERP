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

        $dateType = $this->filters['date_type'] ?? null;
        if ($dateType === 'month') {
            $year = $this->filters['year'] ?? now()->year;
            $month = $this->filters['month'] ?? now()->month;
            $parts[] = "Kỳ: Tháng " . str_pad($month, 2, '0', STR_PAD_LEFT) . "/{$year}";
        } elseif ($dateType === 'quarter') {
            $year = $this->filters['year'] ?? now()->year;
            $quarter = $this->filters['quarter'] ?? ceil(now()->month / 3);
            $parts[] = "Kỳ: Quý {$quarter}/{$year}";
        } elseif ($dateType === 'custom') {
            $startDate = $this->filters['start_date'] ?? null;
            $endDate = $this->filters['end_date'] ?? null;
            if ($startDate && $endDate) {
                $parts[] = "Khoảng thời gian: từ " . date('d/m/Y', strtotime($startDate)) . " đến " . date('d/m/Y', strtotime($endDate));
            } elseif ($startDate) {
                $parts[] = "Khoảng thời gian: từ " . date('d/m/Y', strtotime($startDate));
            } elseif ($endDate) {
                $parts[] = "Khoảng thời gian: đến " . date('d/m/Y', strtotime($endDate));
            }
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
        $q         = $this->filters['q']         ?? null;
        $status    = $this->filters['status']    ?? null;
        $dateType  = $this->filters['date_type']  ?? null;
        $year      = $this->filters['year']      ?? null;
        $month     = $this->filters['month']     ?? null;
        $quarter   = $this->filters['quarter']   ?? null;
        $startDate = $this->filters['start_date'] ?? null;
        $endDate   = $this->filters['end_date']   ?? null;

        $dateRange = null;
        if ($dateType === 'month') {
            if ($year && $month) {
                $start = \Carbon\Carbon::create($year, $month, 1)->startOfDay();
                $end   = \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                $dateRange = [$start, $end];
            }
        } elseif ($dateType === 'quarter') {
            if ($year && $quarter) {
                $startMonth = 3 * $quarter - 2;
                $endMonth   = 3 * $quarter;
                $start = \Carbon\Carbon::create($year, $startMonth, 1)->startOfDay();
                $end   = \Carbon\Carbon::create($year, $endMonth, 1)->endOfMonth()->endOfDay();
                $dateRange = [$start, $end];
            }
        }

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
            ->when($dateType === 'month' && $dateRange, fn ($query) => $query->whereBetween('order_date', $dateRange))
            ->when($dateType === 'quarter' && $dateRange, fn ($query) => $query->whereBetween('order_date', $dateRange))
            ->when($dateType === 'custom', function ($query) use ($startDate, $endDate) {
                if ($startDate) {
                    $query->where('order_date', '>=', \Carbon\Carbon::parse($startDate)->startOfDay());
                }
                if ($endDate) {
                    $query->where('order_date', '<=', \Carbon\Carbon::parse($endDate)->endOfDay());
                }
            })
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
