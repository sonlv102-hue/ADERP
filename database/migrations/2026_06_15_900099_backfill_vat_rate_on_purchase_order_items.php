<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill vat_rate trên purchase_order_items từ product.vat_percent.
     *
     * Lý do: migration 900041 thêm cột vat_rate nullable, nhưng các PO tạo trước đó
     * có vat_rate = NULL, khiến UI hiển thị VAT% = 0 dù header tax_amount > 0.
     *
     * Logic: SET vat_rate = product.vat_percent khi vat_rate IS NULL và product_id IS NOT NULL.
     * Không đụng vào vat_rate đã có giá trị (kể cả 0 — có thể là hàng miễn thuế).
     */
    public function up(): void
    {
        // Dùng subquery — works on both PostgreSQL and SQLite
        DB::statement("
            UPDATE purchase_order_items
            SET vat_rate = COALESCE(
                (SELECT vat_percent FROM products WHERE products.id = purchase_order_items.product_id),
                0
            )
            WHERE vat_rate IS NULL
        ");
    }

    public function down(): void
    {
        // Không thể rollback dữ liệu — đây là data-only migration
    }
};
