<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_extra_cost_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_expense_id')->constrained('project_expenses')->cascadeOnDelete();
            $table->date('transfer_date');
            $table->string('debit_account', 20)->default('154');  // always TK 154 or sub-account
            $table->string('credit_account', 20);                 // original debit_account of expense
            $table->bigInteger('amount');                         // amount transferred (before VAT)
            $table->string('description')->nullable();
            $table->string('status', 20)->default('posted');      // posted | cancelled
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->foreignId('project_wip_entry_id')->nullable()->constrained('project_wip_entries')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampsTz();

            $table->index(['project_expense_id', 'status']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_extra_cost_transfers');
    }
};
