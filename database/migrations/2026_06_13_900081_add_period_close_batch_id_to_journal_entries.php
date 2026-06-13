<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('period_close_batch_id')
                  ->nullable()
                  ->after('void_reason')
                  ->constrained('period_close_batches')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['period_close_batch_id']);
            $table->dropColumn('period_close_batch_id');
        });
    }
};
