<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_subcontract_payments', function (Blueprint $table) {
            $table->boolean('pit_withholding_enabled')->default(false)->after('payment_method');
            $table->decimal('pit_rate', 5, 2)->nullable()->after('pit_withholding_enabled');
            $table->decimal('pit_amount', 15, 2)->default(0)->after('pit_rate');
        });
    }

    public function down(): void
    {
        Schema::table('project_subcontract_payments', function (Blueprint $table) {
            $table->dropColumn(['pit_withholding_enabled', 'pit_rate', 'pit_amount']);
        });
    }
};
