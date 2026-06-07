<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Lưu JE khi chi lương qua ngân hàng (Dr 334 / Cr 112)
            $table->unsignedBigInteger('salary_journal_entry_id')->nullable()->after('cash_voucher_id');
            $table->foreign('salary_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropForeign(['salary_journal_entry_id']);
            $table->dropColumn('salary_journal_entry_id');
        });
    }
};
