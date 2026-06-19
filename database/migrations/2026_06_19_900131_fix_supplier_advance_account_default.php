<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Chuẩn hóa TK ứng trước NCC:
     * 1. Đổi DEFAULT của cột account_code từ '3311' → '331UT' (PostgreSQL only).
     * 2. Backfill toàn bộ bản ghi đang có account_code = '3311' sang '331UT'.
     *
     * Lý do: trả trước NCC là tài sản (Dư Nợ TK 331UT), không phải phải trả (Dư Có TK 3311).
     * Dữ liệu cũ dùng default '3311' từ migration 900096 sẽ được backfill ở đây.
     *
     * Sau migration này, SupplierAdvanceService.create() cũng sẽ luôn set account_code = 331UT
     * nên DB default chỉ còn là safety net.
     */
    public function up(): void
    {
        // Đổi default (PostgreSQL only — SQLite không hỗ trợ ALTER COLUMN)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_opening_advances ALTER COLUMN account_code SET DEFAULT '331UT'");
        }

        // Backfill tất cả advance đang dùng '3311' → '331UT'
        // (khoản trả trước NCC không được nằm trên TK phải trả 3311)
        DB::table('supplier_opening_advances')
            ->where('account_code', '3311')
            ->update(['account_code' => '331UT', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_opening_advances ALTER COLUMN account_code SET DEFAULT '3311'");
        }
        // Không reverse dữ liệu — 331UT là đúng nghiệp vụ
    }
};
