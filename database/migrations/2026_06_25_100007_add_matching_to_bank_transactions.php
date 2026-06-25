<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            // Matching workflow
            $table->string('match_status')->default('unmatched')->after('internal_account_id');
            $table->string('matched_party_type')->nullable()->after('match_status');   // customer|supplier
            $table->unsignedBigInteger('matched_party_id')->nullable()->after('matched_party_type');
            $table->string('matched_document_type')->nullable()->after('matched_party_id'); // invoice|purchase_invoice|supplier_advance|customer_advance
            $table->unsignedBigInteger('matched_document_id')->nullable()->after('matched_document_type');
            $table->unsignedSmallInteger('confidence_score')->nullable()->after('matched_document_id');
            $table->text('match_note')->nullable()->after('confidence_score');

            // Suggested tx type (overrides tx_type for matching purpose)
            $table->string('suggested_tx_type')->nullable()->after('match_note');

            // FK links created when posting
            $table->foreignId('customer_bank_account_id')
                ->nullable()->after('supplier_bank_account_id')
                ->constrained('customer_bank_accounts')->nullOnDelete();
            $table->foreignId('cash_voucher_id')
                ->nullable()->after('journal_entry_id')
                ->constrained('cash_vouchers')->nullOnDelete();
            $table->foreignId('confirmed_by')
                ->nullable()->after('cash_voucher_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');

            $table->index('match_status');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['customer_bank_account_id']);
            $table->dropForeign(['cash_voucher_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropIndex(['match_status']);
            $table->dropColumn([
                'match_status', 'matched_party_type', 'matched_party_id',
                'matched_document_type', 'matched_document_id', 'confidence_score',
                'match_note', 'suggested_tx_type',
                'customer_bank_account_id', 'cash_voucher_id', 'confirmed_by', 'confirmed_at',
            ]);
        });
    }
};
