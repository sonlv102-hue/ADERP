<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table) {
            $table->string('status', 20)->default('posted')->after('net_book_value_after');
            $table->date('period_start')->nullable()->after('period');
            $table->date('period_end')->nullable()->after('period_start');
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('journal_entry_id');
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->unsignedBigInteger('posted_by')->nullable()->after('reversed_at');
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('reversed_by')->nullable()->after('posted_by');
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
            // notes already exists from create migration
        });

        // Mark existing records as posted (they were computed historically)
        DB::statement("UPDATE fixed_asset_depreciations SET status = 'posted', posted_at = created_at");
    }

    public function down(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['posted_by']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn([
                'status', 'period_start', 'period_end',
                'journal_entry_id', 'posted_at', 'reversed_at',
                'posted_by', 'reversed_by',
            ]);
        });
    }
};
