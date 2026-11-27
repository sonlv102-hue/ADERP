<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory integrity (A1/A5):
 * - stock_exit_items.avco_qty / avco_amount: phần số lượng/giá trị thực sự trừ từ AVCO kho chung
 *   khi xác nhận phiếu xuất — để hủy phiếu hoàn trả đúng phần AVCO (kể cả phiếu dự án FIFO+AVCO).
 *   NULL = phiếu xác nhận trước migration này (legacy) → cancelExit suy ra từ lot allocations.
 * - project_inventory_lots: lot tạo từ phiếu điều chuyển dự án không có phiếu nhập nguồn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->decimal('avco_qty', 15, 3)->nullable()->after('cost_source');
            $table->decimal('avco_amount', 18, 2)->nullable()->after('avco_qty');
        });

        Schema::table('project_inventory_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_entry_id')->nullable()->change();
            $table->unsignedBigInteger('stock_entry_item_id')->nullable()->change();
            $table->foreignId('source_stock_exit_id')->nullable()->after('stock_entry_item_id')
                ->constrained('stock_exits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_inventory_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_stock_exit_id');
        });

        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->dropColumn(['avco_qty', 'avco_amount']);
        });
        // stock_entry_id / stock_entry_item_id giữ nullable khi rollback: đảo NOT NULL sẽ lỗi nếu đã có lot điều chuyển.
    }
};
