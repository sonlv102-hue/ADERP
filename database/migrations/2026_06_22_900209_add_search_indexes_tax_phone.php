<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->index('tax_code', 'idx_suppliers_tax_code');
            $table->index('phone', 'idx_suppliers_phone');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('tax_code', 'idx_customers_tax_code');
            $table->index('phone', 'idx_customers_phone');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('idx_suppliers_tax_code');
            $table->dropIndex('idx_suppliers_phone');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('idx_customers_tax_code');
            $table->dropIndex('idx_customers_phone');
        });
    }
};
