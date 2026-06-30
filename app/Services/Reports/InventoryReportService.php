<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReportService
{
    // Joins chuẩn để lấy ngày chứng từ NK/XK thay vì created_at
    private const JOINS = "
        LEFT JOIN stock_entries se ON sm.source_id = se.id
            AND sm.source_type = 'App\\\\Models\\\\StockEntry'
        LEFT JOIN stock_exits sx ON sm.source_id = sx.id
            AND sm.source_type = 'App\\\\Models\\\\StockExit'";

    private const DOC_DATE = "COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))";

    // Mệnh đề lọc bỏ movements voided (migration 900213)
    private const ACTIVE_FILTER = "(sm.status IS NULL OR sm.status = 'active')";

    /**
     * Build sub-query aggregate stock_movements → 1 query thay vì correlated subqueries
     *
     * @param string $dateFrom   YYYY-MM-DD — ngày bắt đầu kỳ báo cáo
     * @param string $dateTo     YYYY-MM-DD — ngày kết thúc kỳ báo cáo
     * @param int|null $warehouseId  null = toàn hệ thống
     */
    private function buildSmAggregate(string $dateFrom, string $dateTo, ?int $warehouseId): Builder
    {
        return DB::table('stock_movements as sm')
            ->leftJoin('stock_entries as se', function ($join) {
                $join->on('sm.source_id', '=', 'se.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockEntry');
            })
            ->leftJoin('stock_exits as sx', function ($join) {
                $join->on('sm.source_id', '=', 'sx.id')
                     ->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->whereRaw(self::ACTIVE_FILTER)
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->selectRaw(
                "sm.product_id,
                 SUM(CASE WHEN " . self::DOC_DATE . " < ? THEN sm.quantity ELSE 0 END) as stock_begin,
                 SUM(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity > 0 THEN sm.quantity ELSE 0 END) as stock_in,
                 SUM(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as stock_out,
                 MAX(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity > 0 THEN " . self::DOC_DATE . " END) as last_in_date,
                 MAX(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity < 0 THEN " . self::DOC_DATE . " END) as last_out_date,
                 SUM(CASE WHEN " . self::DOC_DATE . " < ? THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_begin,
                 SUM(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity > 0 THEN COALESCE(sm.amount, 0) ELSE 0 END) as amount_in,
                 SUM(CASE WHEN " . self::DOC_DATE . " BETWEEN ? AND ? AND sm.quantity < 0 THEN ABS(COALESCE(sm.amount, 0)) ELSE 0 END) as amount_out",
                [
                    $dateFrom,
                    $dateFrom, $dateTo,
                    $dateFrom, $dateTo,
                    $dateFrom, $dateTo,
                    $dateFrom, $dateTo,
                    $dateFrom,
                    $dateFrom, $dateTo,
                    $dateFrom, $dateTo,
                ]
            )
            ->groupBy('sm.product_id');
    }

    /**
     * Build query chính joining products với sm aggregate
     */
    private function buildMainQuery(array $filters): Builder
    {
        $dateFrom    = $filters['date_from']    ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']      ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $search      = $filters['search']       ?? null;
        $categoryId  = $filters['category_id']  ?? null;

        $smAgg = $this->buildSmAggregate($dateFrom, $dateTo, $warehouseId);

        return DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoinSub($smAgg, 'sm_agg', 'sm_agg.product_id', '=', 'products.id')
            ->select([
                'products.id',
                'products.code',
                'products.name',
                'products.unit',
                'products.cost_price',
                'product_categories.name as category',
                DB::raw('COALESCE(sm_agg.stock_begin, 0) as stock_begin'),
                DB::raw('COALESCE(sm_agg.stock_in, 0) as stock_in'),
                DB::raw('COALESCE(sm_agg.stock_out, 0) as stock_out'),
                DB::raw('sm_agg.last_in_date'),
                DB::raw('sm_agg.last_out_date'),
                DB::raw('COALESCE(sm_agg.amount_begin, 0) as amount_begin'),
                DB::raw('COALESCE(sm_agg.amount_in, 0) as amount_in'),
                DB::raw('COALESCE(sm_agg.amount_out, 0) as amount_out'),
            ])
            ->whereNull('products.deleted_at')
            ->when($search, fn ($q) => $q->where(fn ($q2) =>
                $q2->where('products.code', 'ilike', "%{$search}%")
                   ->orWhere('products.name', 'ilike', "%{$search}%")
            ))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->orderBy('products.code');
    }

    /**
     * Map raw DB row → array dùng cho UI và Excel (nguồn sự thật duy nhất)
     * Giá trị đầu kỳ lấy từ sm.amount thực tế — không tính lại bằng cost_price
     */
    public static function mapRow(object $row): array
    {
        $begin    = (float) $row->stock_begin;
        $in       = (float) $row->stock_in;
        $out      = (float) $row->stock_out;
        $beginVal = (float) $row->amount_begin;
        $inVal    = (float) $row->amount_in;
        $outVal   = (float) $row->amount_out;

        return [
            'id'            => $row->id,
            'code'          => $row->code,
            'name'          => $row->name,
            'unit'          => $row->unit,
            'category'      => $row->category ?? '—',
            'cost_price'    => (float) $row->cost_price,
            'stock_begin'   => $begin,
            'stock_in'      => $in,
            'stock_out'     => $out,
            'stock_end'     => $begin + $in - $out,
            'value_begin'   => $beginVal,
            'value_in'      => $inVal,
            'value_out'     => $outVal,
            'value_end'     => $beginVal + $inVal - $outVal,
            'last_in_date'  => $row->last_in_date,
            'last_out_date' => $row->last_out_date,
        ];
    }

    /**
     * Paginated rows cho UI
     */
    public function buildPaginatedRows(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $paginator = $this->buildMainQuery($filters)->paginate($perPage);
        $paginator->through(fn ($row) => self::mapRow($row));
        return $paginator;
    }

    /**
     * All rows cho Excel export (không paginate)
     */
    public function buildAllRows(array $filters): Collection
    {
        return $this->buildMainQuery($filters)->get()->map(fn ($row) => self::mapRow($row));
    }

    /**
     * Summary totals cho UI footer
     */
    public function buildSummary(array $filters): array
    {
        $dateFrom    = $filters['date_from']    ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']      ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $search      = $filters['search']       ?? null;
        $categoryId  = $filters['category_id']  ?? null;

        $smAgg = $this->buildSmAggregate($dateFrom, $dateTo, $warehouseId);

        $agg = DB::table('products')
            ->leftJoinSub($smAgg, 'sms', 'sms.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->when($search, fn ($q) => $q->where(fn ($q2) =>
                $q2->where('products.code', 'ilike', "%{$search}%")
                   ->orWhere('products.name', 'ilike', "%{$search}%")
            ))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->selectRaw(
                "SUM(COALESCE(sms.amount_begin, 0)) as begin_val,
                 SUM(COALESCE(sms.amount_in,    0)) as in_val,
                 SUM(COALESCE(sms.amount_out,   0)) as out_val,
                 SUM(COALESCE(sms.amount_begin, 0) + COALESCE(sms.amount_in, 0) - COALESCE(sms.amount_out, 0)) as end_val"
            )
            ->first();

        return [
            'total_begin_value' => (float) ($agg->begin_val ?? 0),
            'total_in_value'    => (float) ($agg->in_val    ?? 0),
            'total_out_value'   => (float) ($agg->out_val   ?? 0),
            'total_end_value'   => (float) ($agg->end_val   ?? 0),
        ];
    }

    /**
     * Debug single product — trả về chi tiết để so sánh UI vs Excel
     */
    public function debugProductRow(string $productCode, array $filters): ?array
    {
        $row = $this->buildMainQuery(array_merge($filters, ['search' => $productCode]))
            ->where('products.code', $productCode)
            ->first();

        if (!$row) {
            return null;
        }

        return array_merge(self::mapRow($row), [
            'product_id'         => $row->id,
            'source_description' => 'SUM(sm.amount) từ stock_movements (không dùng inventory_balances, không dùng cost_price)',
        ]);
    }
}
