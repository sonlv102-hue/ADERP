<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Phân loại nguồn gốc bút toán — dùng để lọc đầu kỳ khỏi phát sinh kỳ
            $table->string('source_type', 50)->nullable()->after('reference_id')
                  ->comment('normal|opening_balance|inventory_opening|receivable_opening|payable_opening');

            // Kỳ tài chính dạng YYYY-MM (vd: 2026-01)
            $table->string('fiscal_period', 7)->nullable()->after('source_type');

            // Nếu true: không tính vào phát sinh trong kỳ của bảng cân đối phát sinh
            $table->boolean('exclude_from_period_movement')->default(false)->after('fiscal_period');
        });

        // Index hỗ trợ query TrialBalance filter
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index('source_type', 'je_source_type_idx');
            $table->index('exclude_from_period_movement', 'je_exclude_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_source_type_idx');
            $table->dropIndex('je_exclude_period_idx');
            $table->dropColumn(['source_type', 'fiscal_period', 'exclude_from_period_movement']);
        });
    }
};
