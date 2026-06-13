<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            // Truy vết về sản phẩm cụ thể (cho báo cáo vật tư dự án theo từng hạng mục)
            $table->foreignId('product_id')->nullable()->after('journal_entry_id')
                  ->constrained('products')->nullOnDelete();

            // Số lượng và đơn giá để tính lại amount nếu cần
            $table->decimal('quantity', 12, 3)->nullable()->after('product_id');
            $table->decimal('unit_cost', 18, 2)->nullable()->after('quantity');

            // FK tới dòng xuất kho cụ thể — link trực tiếp kế toán ↔ vật tư
            $table->foreignId('stock_exit_item_id')->nullable()->after('unit_cost')
                  ->constrained('stock_exit_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['quantity', 'unit_cost']);
            $table->dropConstrainedForeignId('stock_exit_item_id');
        });
    }
};
