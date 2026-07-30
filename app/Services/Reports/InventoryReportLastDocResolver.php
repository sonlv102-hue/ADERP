<?php

namespace App\Services\Reports;

/**
 * Sinh subquery tương quan (correlated) lấy mã chứng từ nhập/xuất GẦN NHẤT
 * trong kỳ cho từng sản phẩm — đi cùng cột last_in_date/last_out_date đã có
 * trong InventoryReportService. Tách riêng để giữ service chính ≤200 dòng.
 */
class InventoryReportLastDocResolver
{
    /**
     * warehouseId đã ép kiểu int ở nơi gọi nên an toàn khi nối chuỗi trực tiếp.
     */
    public static function subquery(bool $isIn, ?int $warehouseId): string
    {
        $qtyCond = $isIn ? 'sm2.quantity > 0' : 'sm2.quantity < 0';
        $whCond  = $warehouseId ? "AND sm2.warehouse_id = {$warehouseId}" : '';

        return "(SELECT COALESCE(se2.code, sx2.code)
            FROM stock_movements sm2
            LEFT JOIN stock_entries se2 ON sm2.source_id = se2.id AND sm2.source_type = 'App\\Models\\StockEntry'
            LEFT JOIN stock_exits sx2 ON sm2.source_id = sx2.id AND sm2.source_type = 'App\\Models\\StockExit'
            WHERE sm2.product_id = products.id
              AND (sm2.status IS NULL OR sm2.status = 'active')
              {$whCond}
              AND {$qtyCond}
              AND COALESCE(se2.entry_date, sx2.exit_date, DATE(sm2.created_at)) BETWEEN ? AND ?
            ORDER BY COALESCE(se2.entry_date, sx2.exit_date, DATE(sm2.created_at)) DESC, sm2.id DESC
            LIMIT 1)";
    }
}
