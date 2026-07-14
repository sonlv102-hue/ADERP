<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subcontract_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontract_id')->constrained('project_subcontracts')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->date('advance_date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20); // cash | bank
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->string('debit_account', 20);
            $table->string('credit_account', 20);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->string('status', 20)->default('posted'); // posted|cancelled
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['subcontract_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subcontract_advances');
    }
};
