<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_posting_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('posting_type', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->date('posting_date');
            $table->string('description', 500);
            $table->json('lines');
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz();

            $table->unique(['source_type', 'source_id', 'posting_type']);
            $table->index(['status', 'source_type']);
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_posting_jobs');
    }
};
