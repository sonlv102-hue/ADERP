<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('counterpart_bank', 150)->nullable()->after('running_balance');
            $table->string('counterpart_account', 50)->nullable()->after('counterpart_bank');
            $table->string('counterpart_name', 200)->nullable()->after('counterpart_account');
            $table->string('tx_type', 30)->default('unknown')->after('counterpart_name');
            $table->unsignedBigInteger('supplier_bank_account_id')->nullable()->after('tx_type');
            $table->unsignedBigInteger('internal_account_id')->nullable()->after('supplier_bank_account_id');
            $table->text('alert_note')->nullable()->after('internal_account_id');
            $table->index('counterpart_account');
            $table->index('tx_type');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex(['counterpart_account']);
            $table->dropIndex(['tx_type']);
            $table->dropColumn(['counterpart_bank', 'counterpart_account', 'counterpart_name',
                'tx_type', 'supplier_bank_account_id', 'internal_account_id', 'alert_note']);
        });
    }
};
