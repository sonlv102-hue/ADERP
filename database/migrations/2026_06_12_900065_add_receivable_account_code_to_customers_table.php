<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('receivable_account_code', 10)->nullable()->after('notes');
            $table->foreign('receivable_account_code')
                  ->references('code')->on('account_codes')
                  ->restrictOnDelete();
        });

        // Seed existing customers: default 1311 (Khách hàng bán hàng — trong nước)
        DB::table('customers')->whereNull('receivable_account_code')
            ->update(['receivable_account_code' => '1311']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['receivable_account_code']);
            $table->dropColumn('receivable_account_code');
        });
    }
};
