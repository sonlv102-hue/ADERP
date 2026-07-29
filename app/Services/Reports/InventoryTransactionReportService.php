<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sổ chi tiết Nhập-Xuất-Tồn (Mẫu S10-DN): 1 dòng = 1 giao dịch (nhập HOẶC xuất),
 * kèm dòng tồn đầu kỳ + dòng tồn cuối kỳ per sản phẩm. Không tái sử dụng
 * InventoryReportService (báo cáo tổng hợp khác mục đích, giữ nguyên).
 */
class InventoryTransactionReportService
{
    private const ACTIVE_FILTER = "(sm.status IS NULL OR sm.status = 'active')";

    private const DOC_DATE = "COALESCE(se.entry_date, sx.exit_date, st.transfer_date, sr.return_date, pr.return_date, ic.count_date, DATE(sm.created_at))";

    // '||' thay vì CONCAT() để tương thích cả PostgreSQL (prod) và SQLite (test)
    private const DOC_CODE = "COALESCE(se.code, sx.code, st.code, sr.code, pr.code, ic.code, 'DK-' || sm.id)";

    private function addSourceJoins(Builder $query): Builder
    {
        return $query
            ->leftJoin('stock_entries as se', function ($join) {
                $join->on('sm.source_id', '=', 'se.id')->where('sm.source_type', '=', 'App\\Models\\StockEntry');
            })
            ->leftJoin('stock_exits as sx', function ($join) {
                $join->on('sm.source_id', '=', 'sx.id')->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->leftJoin('stock_transfers as st', function ($join) {
                $join->on('sm.source_id', '=', 'st.id')->where('sm.source_type', '=', 'App\\Models\\StockTransfer');
            })
            ->leftJoin('sales_returns as sr', function ($join) {
                $join->on('sm.source_id', '=', 'sr.id')->where('sm.source_type', '=', 'App\\Models\\SalesReturn');
            })
            ->leftJoin('purchase_returns as pr', function ($join) {
                $join->on('sm.source_id', '=', 'pr.id')->where('sm.source_type', '=', 'App\\Models\\PurchaseReturn');
            })
            ->leftJoin('inventory_counts as ic', function ($join) {
                $join->on('sm.source_id', '=', 'ic.id')->where('sm.source_type', '=', 'App\\Models\\InventoryCount');
            });
    }

    private function buildProductQuery(array $filters): Builder
    {
        $dateFrom    = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']   ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $productId   = isset($filters['product_id'])   ? (int) $filters['product_id']   : null;
        $search      = $filters['search']     ?? null;
        $categoryId  = $filters['category_id'] ?? null;

        // Gộp tồn đầu kỳ + đếm giao dịch trong kỳ trong 1 subquery join — dùng
        // movement_count để ẩn sản phẩm không có tồn đầu kỳ VÀ không phát sinh
        // gì trong kỳ (tránh bảng dài toàn dòng rỗng).
        $activityAgg = $this->addSourceJoins(DB::table('stock_movements as sm'))
            ->whereRaw(self::ACTIVE_FILTER)
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->selectRaw(
                'sm.product_id,
                 SUM(CASE WHEN ' . self::DOC_DATE . ' < ? THEN sm.quantity ELSE 0 END) as qty_begin,
                 SUM(CASE WHEN ' . self::DOC_DATE . ' < ? THEN COALESCE(sm.amount, 0) ELSE 0 END) as value_begin,
                 SUM(CASE WHEN ' . self::DOC_DATE . ' BETWEEN ? AND ? THEN 1 ELSE 0 END) as movement_count',
                [$dateFrom, $dateFrom, $dateFrom, $dateTo]
            )
            ->groupBy('sm.product_id');

        return DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoinSub($activityAgg, 'ob', 'ob.product_id', '=', 'products.id')
            ->select([
                'products.id', 'products.code', 'products.name', 'products.unit',
                'product_categories.name as category',
                DB::raw('COALESCE(ob.qty_begin, 0) as qty_begin'),
                DB::raw('COALESCE(ob.value_begin, 0) as value_begin'),
            ])
            ->whereNull('products.deleted_at')
            ->whereRaw('(COALESCE(ob.qty_begin, 0) != 0 OR COALESCE(ob.movement_count, 0) > 0)')
            ->when($productId, fn ($q) => $q->where('products.id', $productId))
            ->when($search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('products.code', 'ilike', "%{$search}%")
                ->orWhere('products.name', 'ilike', "%{$search}%")))
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->orderBy('products.code');
    }

    private function fetchMovements(array $productIds, array $filters): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        $dateFrom    = $filters['date_from'] ?? now()->startOfYear()->toDateString();
        $dateTo      = $filters['date_to']   ?? now()->toDateString();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $rows = $this->addSourceJoins(DB::table('stock_movements as sm'))
            ->whereRaw(self::ACTIVE_FILTER)
            ->whereIn('sm.product_id', $productIds)
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->whereRaw(self::DOC_DATE . ' BETWEEN ? AND ?', [$dateFrom, $dateTo])
            ->select([
                'sm.product_id', 'sm.quantity', 'sm.source_type', 'sm.created_at',
                DB::raw('COALESCE(sm.amount, 0) as amount'),
                DB::raw(self::DOC_CODE . ' as doc_code'),
                DB::raw(self::DOC_DATE . ' as doc_date'),
            ])
            // Sắp xếp: ngày chứng từ → số chứng từ → thời gian ghi nhận (created_at) → id
            // (id chỉ để phá thế hòa tuyệt đối, không mang ý nghĩa nghiệp vụ)
            ->orderBy('sm.product_id')
            ->orderByRaw(self::DOC_DATE)
            ->orderByRaw(self::DOC_CODE)
            ->orderBy('sm.created_at')
            ->orderBy('sm.id')
            ->get();

        return $rows->groupBy('product_id');
    }

    public function buildProductPage(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $builder = new InventoryTransactionGroupBuilder();
        $paginator = $this->buildProductQuery($filters)->paginate($perPage);
        $movementsByProduct = $this->fetchMovements(
            collect($paginator->items())->pluck('id')->all(),
            $filters
        );

        $paginator->through(fn ($row) => $builder->build($row, $movementsByProduct->get($row->id, collect())));

        return $paginator;
    }

    public function buildAllProductGroups(array $filters): Collection
    {
        $builder = new InventoryTransactionGroupBuilder();
        $products = $this->buildProductQuery($filters)->get();
        $movementsByProduct = $this->fetchMovements($products->pluck('id')->all(), $filters);

        return $products->map(fn ($row) => $builder->build($row, $movementsByProduct->get($row->id, collect())));
    }
}
