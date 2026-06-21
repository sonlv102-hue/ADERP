<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_extra_cost_transfers', function (Blueprint $table) {
            // FK trỏ về JE gốc của chi phí — dùng để reverse tự động khi hủy chứng từ gốc
            $table->unsignedBigInteger('transfer_from_entry_id')
                  ->nullable()
                  ->after('journal_entry_id');

            $table->index('transfer_from_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_extra_cost_transfers', function (Blueprint $table) {
            $table->dropIndex(['transfer_from_entry_id']);
            $table->dropColumn('transfer_from_entry_id');
        });
    }
};
