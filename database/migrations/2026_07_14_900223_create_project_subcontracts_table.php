<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_subcontracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('contractor_name', 255);
            $table->string('contractor_type', 20); // company | team | individual

            $table->string('contract_no', 100);
            $table->date('contract_date');
            $table->text('scope_of_work')->nullable();
            $table->string('cost_group', 20)->default('subcontractor'); // subcontractor | labor | equipment | transport | other

            $table->decimal('amount_before_vat', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->decimal('advance_rate', 5, 2)->nullable();
            $table->decimal('advance_amount', 15, 2)->default(0); // denormalized, updated by service
            $table->decimal('retention_rate', 5, 2)->nullable();
            $table->decimal('retention_amount', 15, 2)->default(0); // denormalized, updated by service

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('status', 20)->default('draft'); // draft|active|partially_accepted|completed|cancelled
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->unique(['project_id', 'contract_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_subcontracts');
    }
};
