<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // project_id để query tồn kho theo dự án trực tiếp
            $table->foreignId('project_id')->nullable()->after('source_id')
                  ->constrained('projects')->nullOnDelete();

            // unit_cost và amount để tính AVCO/FIFO và báo cáo giá trị tồn kho
            $table->decimal('unit_cost', 18, 2)->nullable()->after('quantity');
            $table->decimal('amount', 18, 2)->nullable()->after('unit_cost');
        });

        // Backfill project_id cho stock_movements từ stock_exits có project_id (cross-DB)
        $sourceType = addslashes(\App\Models\StockExit::class);
        DB::statement("
            UPDATE stock_movements
            SET project_id = (
                SELECT project_id FROM stock_exits
                WHERE stock_exits.id = stock_movements.source_id
            )
            WHERE source_type = '{$sourceType}'
              AND project_id IS NULL
              AND source_id IN (
                SELECT id FROM stock_exits WHERE project_id IS NOT NULL
              )
        ");
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn(['unit_cost', 'amount']);
        });
    }
};
