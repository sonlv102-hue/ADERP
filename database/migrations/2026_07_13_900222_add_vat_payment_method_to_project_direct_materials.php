<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_direct_materials', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable()->after('unit_price');
            $table->decimal('vat_amount', 15, 2)->nullable()->default(0)->after('vat_rate');
            $table->string('payment_method', 20)->nullable()->after('handling_type');
        });
    }

    public function down(): void
    {
        Schema::table('project_direct_materials', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'vat_amount', 'payment_method']);
        });
    }
};
