<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // products
        Schema::table('products', function (Blueprint $table) {
            $table->index('name', 'idx_products_name');
            $table->index('code', 'idx_products_code');
        });

        // suppliers
        Schema::table('suppliers', function (Blueprint $table) {
            $table->index('name', 'idx_suppliers_name');
            $table->index('code', 'idx_suppliers_code');
        });

        // customers
        Schema::table('customers', function (Blueprint $table) {
            $table->index('name', 'idx_customers_name');
            $table->index('code', 'idx_customers_code');
        });

        // projects
        Schema::table('projects', function (Blueprint $table) {
            $table->index('name', 'idx_projects_name');
            $table->index('code', 'idx_projects_code');
        });

        // services
        Schema::table('services', function (Blueprint $table) {
            $table->index('name', 'idx_services_name');
            $table->index('code', 'idx_services_code');
        });

        // account_codes
        Schema::table('account_codes', function (Blueprint $table) {
            $table->index('name', 'idx_account_codes_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_name');
            $table->dropIndex('idx_products_code');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('idx_suppliers_name');
            $table->dropIndex('idx_suppliers_code');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('idx_customers_name');
            $table->dropIndex('idx_customers_code');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('idx_projects_name');
            $table->dropIndex('idx_projects_code');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('idx_services_name');
            $table->dropIndex('idx_services_code');
        });
        Schema::table('account_codes', function (Blueprint $table) {
            $table->dropIndex('idx_account_codes_name');
        });
    }
};
