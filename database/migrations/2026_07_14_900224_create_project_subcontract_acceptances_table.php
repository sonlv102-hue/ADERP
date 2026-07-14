<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subcontract_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontract_id')->constrained('project_subcontracts')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('acceptance_no', 100)->nullable();
            $table->date('acceptance_date');
            $table->text('description')->nullable();

            $table->decimal('amount_before_vat', 15, 2);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);

            $table->string('invoice_no', 100)->nullable();
            $table->date('invoice_date')->nullable();

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('project_wip_entry_id')->nullable()->constrained('project_wip_entries')->nullOnDelete();

            $table->string('status', 20)->default('draft'); // draft|posted|cancelled
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['subcontract_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subcontract_acceptances');
    }
};
