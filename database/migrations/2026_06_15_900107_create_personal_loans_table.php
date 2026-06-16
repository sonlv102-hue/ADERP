<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no', 20)->unique();
            $table->enum('lender_type', ['employee', 'shareholder', 'other']);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('shareholder_id')->nullable()->constrained('shareholders')->nullOnDelete();
            $table->string('lender_name')->nullable();
            $table->decimal('amount', 15, 0);
            $table->decimal('repaid_amount', 15, 0)->default(0);
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->date('loan_date');
            $table->date('due_date')->nullable();
            $table->string('purpose')->nullable();
            $table->foreignId('fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->enum('status', ['draft', 'active', 'partially_repaid', 'repaid', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('personal_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_loan_id')->constrained('personal_loans')->cascadeOnDelete();
            $table->foreignId('fund_id')->constrained('funds');
            $table->date('repayment_date');
            $table->decimal('amount', 15, 0);
            $table->string('description')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_loan_repayments');
        Schema::dropIfExists('personal_loans');
    }
};
