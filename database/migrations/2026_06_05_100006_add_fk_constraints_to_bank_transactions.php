<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreign('supplier_bank_account_id')
                ->references('id')->on('supplier_bank_accounts')
                ->restrictOnDelete();
            $table->foreign('internal_account_id')
                ->references('id')->on('internal_bank_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['supplier_bank_account_id']);
            $table->dropForeign(['internal_account_id']);
        });
    }
};
