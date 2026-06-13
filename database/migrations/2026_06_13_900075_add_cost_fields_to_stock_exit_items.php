<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exit_items', function (Blueprint $table) {
            // project_id kế thừa từ exit header (hoặc override per-line trong tương lai)
            $table->foreignId('project_id')->nullable()->after('stock_exit_id')
                  ->constrained('projects')->nullOnDelete();

            // source_cost = weighted avg cost từ FIFO lot allocation (VND/đơn vị)
            $table->decimal('source_cost', 18, 2)->nullable()->after('unit_price');

            // total_cost = source_cost * quantity (tổng giá trị xuất kho)
            $table->decimal('total_cost', 18, 2)->nullable()->after('source_cost');
        });

        // Backfill project_id từ exit header (cross-DB compatible)
        DB::statement("
            UPDATE stock_exit_items
            SET project_id = (
                SELECT project_id FROM stock_exits
                WHERE stock_exits.id = stock_exit_items.stock_exit_id
            )
            WHERE project_id IS NULL
              AND stock_exit_id IN (
                SELECT id FROM stock_exits WHERE project_id IS NOT NULL
              )
        ");
    }

    public function down(): void
    {
        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['source_cost', 'total_cost']);
        });
    }
};
