<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_bank_accounts', function (Blueprint $table) {
            $table->string('normalized_account_number')->nullable()->after('account_number');
            $table->index('normalized_account_number');
        });

        Schema::table('customer_bank_accounts', function (Blueprint $table) {
            $table->string('normalized_account_number')->nullable()->after('account_number');
            $table->index('normalized_account_number');
        });

        // Backfill existing rows (PostgreSQL only — SQLite không có REGEXP_REPLACE)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("UPDATE supplier_bank_accounts SET normalized_account_number = REGEXP_REPLACE(account_number, '[\s\-\.]', '', 'g') WHERE account_number IS NOT NULL");
            DB::statement("UPDATE customer_bank_accounts SET normalized_account_number = REGEXP_REPLACE(account_number, '[\s\-\.]', '', 'g') WHERE account_number IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('supplier_bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['normalized_account_number']);
            $table->dropColumn('normalized_account_number');
        });
        Schema::table('customer_bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['normalized_account_number']);
            $table->dropColumn('normalized_account_number');
        });
    }
};
