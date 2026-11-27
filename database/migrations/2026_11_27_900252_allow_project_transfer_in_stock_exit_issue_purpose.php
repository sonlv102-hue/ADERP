<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A5: chk_stock_exits_issue_purpose (900074, 13/06) có trước tính năng "Điều chuyển sang kho dự án"
 * (900212, 25/06: UI + validation + StockService đã hỗ trợ 'project_transfer') nhưng không được cập nhật
 * → trên PostgreSQL lưu phiếu loại này lỗi SQL. Bổ sung giá trị hợp lệ; các giá trị khác giữ nguyên.
 */
return new class extends Migration
{
    private const BASE = "'project_cost', 'sale_delivery', 'selling_expense', 'admin_expense', 'internal_use'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE stock_exits DROP CONSTRAINT IF EXISTS chk_stock_exits_issue_purpose');
        DB::statement('ALTER TABLE stock_exits ADD CONSTRAINT chk_stock_exits_issue_purpose CHECK (issue_purpose IS NULL OR issue_purpose IN ('
            . self::BASE . ", 'project_transfer'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('stock_exits')->where('issue_purpose', 'project_transfer')->exists()) {
            throw new RuntimeException('Đã có phiếu project_transfer — không thể khôi phục constraint cũ.');
        }
        DB::statement('ALTER TABLE stock_exits DROP CONSTRAINT IF EXISTS chk_stock_exits_issue_purpose');
        DB::statement('ALTER TABLE stock_exits ADD CONSTRAINT chk_stock_exits_issue_purpose CHECK (issue_purpose IS NULL OR issue_purpose IN (' . self::BASE . '))');
    }
};
