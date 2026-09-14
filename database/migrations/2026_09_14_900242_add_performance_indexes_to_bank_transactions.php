<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index cho Báo cáo dòng tiền tài khoản công ty (spec §13). bank_account_id đã có
 * index đơn qua foreignId()->constrained(), nhưng summary()/transactions() luôn lọc
 * bank_account_id + transaction_date cùng lúc -> cần composite. byParty() group theo
 * party_type+party_id -> cần composite riêng (không trùng bank_tx_cash_flow_idx hiện có).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->index(['bank_account_id', 'transaction_date'], 'bank_tx_account_date_idx');
            $table->index(['party_type', 'party_id'], 'bank_tx_party_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bank_tx_account_date_idx');
            $table->dropIndex('bank_tx_party_idx');
        });
    }
};
