<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            // Mục đích xuất kho — xác định bút toán kế toán phù hợp
            $table->string('issue_purpose', 30)->nullable()->after('item_usage_type');

            // TK chi phí override (chỉ dùng khi issue_purpose = internal_use)
            $table->string('cost_account', 20)->nullable()->after('issue_purpose');

            // TK kho override ở header (fallback cuối trước system default)
            $table->string('inventory_account', 20)->nullable()->after('cost_account');
        });

        // PostgreSQL-only CHECK constraint
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE stock_exits
                ADD CONSTRAINT chk_stock_exits_issue_purpose
                CHECK (issue_purpose IS NULL OR issue_purpose IN (
                    'project_cost', 'sale_delivery', 'selling_expense', 'admin_expense', 'internal_use'
                ))
            ");
        }

        // Backfill issue_purpose từ item_usage_type hiện tại (best-effort)
        DB::statement("
            UPDATE stock_exits
            SET issue_purpose = CASE
                WHEN item_usage_type = 'project'    THEN 'project_cost'
                WHEN item_usage_type = 'commercial' THEN 'sale_delivery'
                ELSE NULL
            END
            WHERE issue_purpose IS NULL
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_exits DROP CONSTRAINT IF EXISTS chk_stock_exits_issue_purpose');
        }

        Schema::table('stock_exits', function (Blueprint $table) {
            $table->dropColumn(['issue_purpose', 'cost_account', 'inventory_account']);
        });
    }
};
