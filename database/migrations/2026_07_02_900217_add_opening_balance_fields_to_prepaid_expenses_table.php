<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepaid_expenses', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('status');
            $table->string('opening_balance_period', 7)->nullable()->after('is_opening_balance');
            $table->string('opening_balance_note', 500)->nullable()->after('opening_balance_period');
            $table->integer('opening_periods_elapsed')->default(0)->after('opening_balance_note');
            $table->unsignedBigInteger('opening_journal_entry_id')->nullable()->after('opening_periods_elapsed');
            $table->foreign('opening_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prepaid_expenses', function (Blueprint $table) {
            $table->dropForeign(['opening_journal_entry_id']);
            $table->dropColumn([
                'is_opening_balance', 'opening_balance_period', 'opening_balance_note',
                'opening_periods_elapsed', 'opening_journal_entry_id',
            ]);
        });
    }
};
