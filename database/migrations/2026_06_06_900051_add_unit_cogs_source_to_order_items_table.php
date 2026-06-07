<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Giá trị hợp lệ:
    //   'snapshot'           — unit_cogs lấy từ products.cost_price tại thời điểm tạo order
    //   'backfill_estimated' — unit_cogs backfill từ giá hiện tại (migration 900050); chỉ là ước tính
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('unit_cogs_source', 30)->nullable()->default(null)->after('unit_cogs');
        });

        // Tất cả row hiện tại đều được backfill bởi migration 900050 → đánh dấu estimated.
        // Các row mới (sau migration này) sẽ được set 'snapshot' bởi OrderController.
        DB::statement("
            UPDATE order_items
            SET unit_cogs_source = 'backfill_estimated'
            WHERE unit_cogs IS NOT NULL
              AND unit_cogs_source IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cogs_source');
        });
    }
};
