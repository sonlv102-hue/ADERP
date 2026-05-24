<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('business_cost', 15, 2)->default(0)->after('cost_price');
            $table->decimal('vat_percent', 5, 2)->default(0)->after('business_cost');
            $table->decimal('total_cost', 15, 2)->default(0)->after('vat_percent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['business_cost', 'vat_percent', 'total_cost']);
        });
    }
};
