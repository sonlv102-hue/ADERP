<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bank_statement_import_batches')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->string('source_type', 20)->default('excel');
            $table->string('original_filename', 255);
            $table->string('file_path', 500)->nullable();
            $table->string('detected_bank_name', 100)->nullable();
            $table->string('detected_account_number', 50)->nullable();
            $table->date('statement_from_date')->nullable();
            $table->date('statement_to_date')->nullable();
            $table->unsignedInteger('total_rows_detected')->default(0);
            $table->unsignedInteger('total_rows_valid')->default(0);
            $table->unsignedInteger('total_rows_duplicate')->default(0);
            $table->unsignedInteger('total_rows_error')->default(0);
            // uploaded / parsed / error / account_mismatch
            $table->string('status', 30)->default('uploaded');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
