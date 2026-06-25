<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_statement_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bank_statement_import_batches')->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('transaction_date')->nullable();
            $table->string('transaction_no', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('counterparty_account_number', 100)->nullable();
            $table->string('counterparty_account_name', 255)->nullable();
            $table->string('counterparty_bank_name', 100)->nullable();
            $table->bigInteger('debit_amount')->default(0);
            $table->bigInteger('credit_amount')->default(0);
            $table->bigInteger('balance_after')->nullable();
            $table->json('raw_data_json')->nullable();
            // valid / duplicate / warning / error
            $table->string('parse_status', 20)->default('valid');
            $table->string('error_message', 500)->nullable();
            $table->string('import_hash', 64)->nullable();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_id', 'parse_status']);
            $table->index('import_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_import_rows');
    }
};
