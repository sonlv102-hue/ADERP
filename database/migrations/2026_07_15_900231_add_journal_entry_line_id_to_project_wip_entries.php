<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->foreignId('journal_entry_line_id')->nullable()
                ->after('journal_entry_id')->constrained('journal_entry_lines')->nullOnDelete();
        });

        // Chống tạo trùng WIP cho cùng 1 dòng bút toán thủ công (source_type=manual_journal_entry).
        // Partial unique: chỉ áp dụng khi journal_entry_line_id có giá trị, vì các nguồn WIP khác
        // (stock_exit, purchase_invoice_item...) không dùng cột này.
        DB::statement(
            'CREATE UNIQUE INDEX project_wip_entries_source_line_unique
             ON project_wip_entries (source_type, journal_entry_line_id)
             WHERE journal_entry_line_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS project_wip_entries_source_line_unique');
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_line_id');
        });
    }
};
