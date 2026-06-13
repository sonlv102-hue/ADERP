<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entry_items', function (Blueprint $table) {
            // Liên kết tới dòng PO — cho phép kiểm soát received_quantity per line
            $table->foreignId('purchase_order_item_id')->nullable()->after('stock_entry_id')
                  ->constrained('purchase_order_items')->nullOnDelete();

            // project_id kế thừa từ PO item; dùng để tạo project_inventory_lot
            $table->foreignId('project_id')->nullable()->after('purchase_order_item_id')
                  ->constrained('projects')->nullOnDelete();

            // unit_cost = unit_price excl VAT tại thời điểm nhận hàng (lưu rõ để dùng trong lot)
            $table->decimal('unit_cost', 18, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('unit_cost');
        });
    }
};
