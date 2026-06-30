<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockMovementDetailReportService
{
    public function buildStockEntryQuery(array $filters): Builder
    {
        $dateFrom    = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']   ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $search      = trim($filters['search'] ?? '');

        $query = DB::table('stock_entry_items as sei')
            ->join('stock_entries as se', 'sei.stock_entry_id', '=', 'se.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'se.warehouse_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'se.supplier_id')
            ->join('products as p', 'p.id', '=', 'sei.product_id')
            ->where('se.status', 'confirmed')
            ->whereBetween('se.entry_date', [$dateFrom, $dateTo])
            ->select([
                'se.id as document_id',
                'se.code as document_code',
                'se.entry_date as document_date',
                'w.name as warehouse',
                's.name as supplier',
                'p.code as product_code',
                'p.name as product_name',
                'p.unit as unit',
                'sei.quantity',
                'sei.unit_price',
                'sei.unit_cost',
            ])
            ->orderBy('se.entry_date')
            ->orderBy('se.code')
            ->orderBy('sei.id');

        if ($warehouseId) {
            $query->where('se.warehouse_id', $warehouseId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('se.code', 'ilike', "%{$search}%")
                    ->orWhere('p.code', 'ilike', "%{$search}%")
                    ->orWhere('p.name', 'ilike', "%{$search}%")
                    ->orWhere('w.name', 'ilike', "%{$search}%")
                    ->orWhere('s.name', 'ilike', "%{$search}%");
            });
        }

        return $query;
    }

    public function buildStockExitQuery(array $filters): Builder
    {
        $dateFrom    = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']   ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $search      = trim($filters['search'] ?? '');

        $query = DB::table('stock_exit_items as sei')
            ->join('stock_exits as se', 'sei.stock_exit_id', '=', 'se.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'se.warehouse_id')
            ->leftJoin('customers as c', 'c.id', '=', 'se.customer_id')
            ->join('products as p', 'p.id', '=', 'sei.product_id')
            ->where('se.status', 'confirmed')
            ->whereBetween('se.exit_date', [$dateFrom, $dateTo])
            ->select([
                'se.id as document_id',
                'se.code as document_code',
                'se.exit_date as document_date',
                'w.name as warehouse',
                'c.name as customer',
                'se.reason as reason',
                'p.code as product_code',
                'p.name as product_name',
                'p.unit as unit',
                'sei.quantity',
                'sei.unit_price',
                'sei.source_cost',
                'sei.total_cost',
            ])
            ->orderBy('se.exit_date')
            ->orderBy('se.code')
            ->orderBy('sei.id');

        if ($warehouseId) {
            $query->where('se.warehouse_id', $warehouseId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('se.code', 'ilike', "%{$search}%")
                    ->orWhere('p.code', 'ilike', "%{$search}%")
                    ->orWhere('p.name', 'ilike', "%{$search}%")
                    ->orWhere('w.name', 'ilike', "%{$search}%")
                    ->orWhere('c.name', 'ilike', "%{$search}%")
                    ->orWhere('se.reason', 'ilike', "%{$search}%");
            });
        }

        return $query;
    }

    public function buildStockEntryRows(array $filters): Collection
    {
        return $this->buildStockEntryQuery($filters)->get()->map(fn ($row) => self::mapStockEntryRow($row));
    }

    public function buildStockExitRows(array $filters): Collection
    {
        return $this->buildStockExitQuery($filters)->get()->map(fn ($row) => self::mapStockExitRow($row));
    }

    public static function mapStockEntryRow(object $row): array
    {
        $quantity = (float) $row->quantity;
        $unitCost = (float) $row->unit_cost;

        return [
            'document_code' => $row->document_code,
            'document_date' => substr($row->document_date, 0, 10),
            'warehouse'     => $row->warehouse ?? '—',
            'partner'       => $row->supplier ?? '—',
            'product_code'  => $row->product_code,
            'product_name'  => $row->product_name,
            'unit'          => $row->unit,
            'quantity'      => $quantity,
            'unit_price'    => (float) $row->unit_price,
            'total_cost'    => $quantity * $unitCost,
        ];
    }

    public static function mapStockExitRow(object $row): array
    {
        return [
            'document_code' => $row->document_code,
            'document_date' => substr($row->document_date, 0, 10),
            'warehouse'     => $row->warehouse ?? '—',
            'partner'       => $row->customer ?? '—',
            'reason'        => $row->reason ?? '—',
            'product_code'  => $row->product_code,
            'product_name'  => $row->product_name,
            'unit'          => $row->unit,
            'quantity'      => (float) $row->quantity,
            'unit_price'    => (float) $row->unit_price,
            'total_cost'    => (float) $row->total_cost,
        ];
    }
}
