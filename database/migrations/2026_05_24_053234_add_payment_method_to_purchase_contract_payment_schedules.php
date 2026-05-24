<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_contract_payment_schedules', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('paid_date');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_contract_payment_schedules', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
