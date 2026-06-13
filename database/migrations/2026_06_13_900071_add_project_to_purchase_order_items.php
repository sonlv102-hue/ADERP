<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // project_id per-line cho phép 1 PO mua cho nhiều dự án khác nhau
            $table->foreignId('project_id')->nullable()->after('purchase_order_id')
                  ->constrained('projects')->nullOnDelete();

            // received_quantity: số lượng đã nhận tích lũy từ các stock_entry_items
            $table->decimal('received_quantity', 12, 3)->default(0)->after('quantity');
        });

        // Backfill project_id từ PO header (cross-DB compatible subquery)
        DB::statement('
            UPDATE purchase_order_items
            SET project_id = (
                SELECT project_id FROM purchase_orders
                WHERE purchase_orders.id = purchase_order_items.purchase_order_id
            )
            WHERE project_id IS NULL
              AND purchase_order_id IN (
                SELECT id FROM purchase_orders WHERE project_id IS NOT NULL
              )
        ');
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('received_quantity');
        });
    }
};
