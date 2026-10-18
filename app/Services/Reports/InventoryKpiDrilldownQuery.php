<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query JOIN dùng chung + map 1 dòng raw SQL → array cho UI/Excel của Inventory KPI Drill-down.
 * Tách riêng khỏi InventoryKpiDrilldownService để giữ service chính ≤200 dòng — theo đúng
 * pattern InventoryReportLastDocResolver đã dùng cho InventoryReportService.
 */
class InventoryKpiDrilldownQuery
{
    // Chép nguyên văn từ InventoryReportService::ACTIVE_FILTER / ::DOC_DATE (private ở đó, không gọi được trực tiếp)
    public const ACTIVE_FILTER = "(sm.status IS NULL OR sm.status = 'active')";
    public const DOC_DATE = "COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))";

    public const SOURCE_LABELS = [
        'StockEntry'              => 'Nhập kho',
        'StockExit'                => 'Xuất kho',
        'StockTransfer'            => 'Chuyển kho',
        'SalesReturn'              => 'Trả hàng bán',
        'PurchaseReturn'           => 'Trả hàng mua',
        'InventoryCount'           => 'Điều chỉnh kiểm kê',
        'InventoryOpeningBalance'  => 'Tồn kho đầu kỳ',
    ];

    // Loại chứng từ có trang chi tiết (route show theo id) để tab Chứng từ gắn link — các loại
    // còn lại (StockTransfer/SalesReturn/PurchaseReturn/InventoryCount/InventoryOpeningBalance)
    // chưa có route show theo id trong hệ thống, hiển thị mã/ngày dạng text, không tự tạo trang mới.
    private const LINKABLE_TYPES = ['StockEntry', 'StockExit'];

    /**
     * Base query: 1 dòng = 1 stock_movement, join products (loại trừ SP đã xóa — khớp điều kiện
     * whereNull('products.deleted_at') của InventoryReportService::buildMainQuery) và các bảng
     * chứng từ nguồn để lấy mã/ngày hiển thị. Mỗi bảng nguồn chỉ khớp theo đúng 1 (source_type,
     * source_id) — quan hệ 1-1 với stock_movements, không làm nhân dòng khi JOIN.
     */
    public static function baseQuery(array $filters): Builder
    {
        $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== ''
            ? (int) $filters['warehouse_id'] : null;
        $categoryId = $filters['category_id'] ?? null;
        $search     = $filters['search'] ?? null;

        return DB::table('stock_movements as sm')
            ->join('products as p', function ($join) {
                $join->on('p.id', '=', 'sm.product_id')->whereNull('p.deleted_at');
            })
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
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
            })
            ->whereRaw(self::ACTIVE_FILTER)
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('p.code', 'ilike', "%{$search}%")
                ->orWhere('p.name', 'ilike', "%{$search}%")));
    }

    public static function map(object $row): array
    {
        $type = class_basename($row->source_type ?? '');

        return [
            'id'                => $row->id,
            'document_id'       => $row->document_id,
            'linkable'          => in_array($type, self::LINKABLE_TYPES, true) && $row->document_id !== null,
            'type_label'        => self::SOURCE_LABELS[$type] ?? $type,
            'doc_date'          => substr((string) $row->doc_date, 0, 10),
            'doc_code'          => $row->doc_code,
            'product_code'      => $row->product_code,
            'product_name'      => $row->product_name,
            'unit'              => $row->unit,
            'warehouse_name'    => $row->warehouse_name,
            'quantity'          => (float) $row->quantity,
            'unit_cost'         => $row->unit_cost !== null ? (float) $row->unit_cost : null,
            'amount'            => $row->amount !== null ? (float) $row->amount : null,
            'has_source_value'  => $row->amount !== null,
            'notes'             => $row->notes,
            'source_type'       => $type,
        ];
    }

    public static function label(string $sourceType): string
    {
        return self::SOURCE_LABELS[class_basename($sourceType)] ?? class_basename($sourceType);
    }
}
