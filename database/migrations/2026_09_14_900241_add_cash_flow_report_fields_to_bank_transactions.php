<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cột mới cho "Báo cáo dòng tiền tài khoản công ty" — hoàn toàn tách biệt với
 * matched_party_type/id, matched_document_type/id, tx_type, internal_status,
 * match_status vốn đang được BankTransactionMatchingService/
 * BankTransactionAllocationService/InternalTransferReportController ghi chủ động.
 * Chỉ được ghi bởi CashFlowClassificationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('cash_flow_category_id')->nullable()
                ->after('match_note')->constrained('cash_flow_categories')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->after('cash_flow_category_id')->constrained('projects')->nullOnDelete();
            $table->string('contract_type', 30)->nullable()->after('project_id');
            $table->unsignedBigInteger('contract_id')->nullable()->after('contract_type');
            $table->string('party_type', 30)->nullable()->after('contract_id');
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');
            $table->string('party_name', 255)->nullable()->after('party_id');
            $table->foreignId('responsible_user_id')->nullable()
                ->after('party_name')->constrained('users')->nullOnDelete();
            $table->text('cash_flow_note')->nullable()->after('responsible_user_id');
            $table->foreignId('paired_transaction_id')->nullable()
                ->after('cash_flow_note')->constrained('bank_transactions')->nullOnDelete();
            $table->unique('paired_transaction_id');
            $table->index(['cash_flow_category_id', 'party_type', 'project_id'], 'bank_tx_cash_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropIndex('bank_tx_cash_flow_idx');
            $table->dropConstrainedForeignId('paired_transaction_id');
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn(['contract_type', 'contract_id', 'party_type', 'party_id', 'party_name', 'cash_flow_note']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('cash_flow_category_id');
        });
    }
};
