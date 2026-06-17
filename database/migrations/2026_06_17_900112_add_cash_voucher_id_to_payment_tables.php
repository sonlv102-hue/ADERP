<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_voucher_id')->nullable()->after('fund_id')
                ->constrained('cash_vouchers')->nullOnDelete();
        });

        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            $table->foreignId('cash_voucher_id')->nullable()->after('fund_id')
                ->constrained('cash_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_voucher_id']);
            $table->dropColumn('cash_voucher_id');
        });

        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['cash_voucher_id']);
            $table->dropColumn('cash_voucher_id');
        });
    }
};
