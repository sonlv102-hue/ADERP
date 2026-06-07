<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // products.item_type giá trị hợp lệ:
    //   'goods'    — hàng hóa vật lý → TK 5111
    //   'service'  — dịch vụ → TK 5113
    //   'software' — phần mềm/license → CẦN KẾ TOÁN XÁC NHẬN
    //   'other'    — loại khác → CẦN KẾ TOÁN XÁC NHẬN
    //
    // order_items.revenue_account_code:
    //   snapshot tại thời điểm tạo đơn hàng; dùng khi post bút toán hóa đơn.
    //   null = chưa có mapping, InvoiceService sẽ log warning và fallback 5111.
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('item_type', 20)->default('goods')->after('category_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('revenue_account_code', 10)->nullable()->after('unit_cogs_source');
        });

        // Backfill order_items:
        // - dòng dịch vụ (service_id IS NOT NULL) → 5113
        // - dòng hàng hóa (product_id IS NOT NULL) → 5111 (vì item_type mặc định là goods)
        DB::statement("
            UPDATE order_items
            SET revenue_account_code = CASE
                WHEN service_id IS NOT NULL THEN '5113'
                WHEN product_id IS NOT NULL THEN '5111'
                ELSE NULL
            END
            WHERE revenue_account_code IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('revenue_account_code');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};
