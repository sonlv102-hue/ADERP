<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->foreignId('cash_voucher_id')
                ->nullable()
                ->after('bank_account_id')
                ->constrained('cash_vouchers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\CashVoucher::class);
            $table->dropColumn('cash_voucher_id');
        });
    }
};
