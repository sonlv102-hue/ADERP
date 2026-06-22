<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->string('labor_type', 30)->nullable()->after('category');
            $table->boolean('pit_withholding_enabled')->default(false)->after('vat_amount');
            $table->decimal('pit_rate', 5, 2)->nullable()->after('pit_withholding_enabled');
            $table->integer('pit_amount')->default(0)->after('pit_rate');
            $table->integer('net_payment_amount')->default(0)->after('pit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropColumn([
                'labor_type', 'pit_withholding_enabled', 'pit_rate', 'pit_amount', 'net_payment_amount',
            ]);
        });
    }
};
