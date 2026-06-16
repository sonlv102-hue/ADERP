<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_expense_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_no', 20)->unique();
            $table->enum('person_type', ['employee', 'shareholder', 'other']);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('shareholder_id')->nullable()->constrained('shareholders')->nullOnDelete();
            $table->string('person_name')->nullable();
            $table->date('expense_date');
            $table->string('description');
            $table->decimal('total_amount', 15, 0)->default(0);
            $table->decimal('vat_amount', 15, 0)->default(0);
            $table->enum('status', ['draft', 'posted', 'reimbursed'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reimburse_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reimbursed_fund_id')->nullable()->constrained('funds')->nullOnDelete();
            $table->timestamp('reimbursed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('personal_expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_expense_report_id')
                ->constrained('personal_expense_reports')->cascadeOnDelete();
            $table->string('expense_account', 20);
            $table->string('description');
            $table->decimal('amount', 15, 0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 0)->default(0);
            $table->decimal('net_amount', 15, 0)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_expense_lines');
        Schema::dropIfExists('personal_expense_reports');
    }
};
