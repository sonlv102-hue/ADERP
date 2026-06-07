<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot giá vốn kế toán (chưa VAT) tại thời điểm tạo đơn hàng.
            // = products.cost_price / (1 + products.vat_percent / 100)
            // Null cho service items (không có giá vốn hàng hóa).
            $table->decimal('unit_cogs', 15, 2)->nullable()->default(null)->after('unit_price');
        });

        // Backfill đơn hàng cũ: dùng cost_price/vat_percent hiện tại làm ước tính.
        // Đơn hàng cũ mà cost_price đã thay đổi sau khi bán sẽ có sai số.
        // Dùng correlated subquery thay vì UPDATE...FROM để tương thích SQLite (test) và PostgreSQL (prod).
        DB::statement("
            UPDATE order_items
            SET unit_cogs = (
                SELECT ROUND(
                    p.cost_price / (1 + COALESCE(NULLIF(p.vat_percent, 0), 0) / 100.0),
                    2
                )
                FROM products p
                WHERE p.id = order_items.product_id
            )
            WHERE product_id IS NOT NULL
              AND unit_cogs IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_cogs');
        });
    }
};
