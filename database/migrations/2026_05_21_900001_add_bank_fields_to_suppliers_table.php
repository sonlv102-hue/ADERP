<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('address');
            $table->string('bank_account')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account');
            $table->string('bank_branch')->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account', 'bank_account_name', 'bank_branch']);
        });
    }
};
