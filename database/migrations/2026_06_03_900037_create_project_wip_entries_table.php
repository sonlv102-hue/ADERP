<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_wip_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('cost_type')->default('material'); // material|labor|subcontract|overhead|other
            $table->bigInteger('amount');
            $table->string('description')->nullable();
            $table->date('entry_date');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'cost_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wip_entries');
    }
};
