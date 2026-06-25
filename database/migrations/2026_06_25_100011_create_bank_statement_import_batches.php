<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_statement_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->string('source_type', 20)->default('excel');
            $table->unsignedSmallInteger('total_files')->default(0);
            $table->unsignedInteger('total_rows_detected')->default(0);
            $table->unsignedInteger('total_rows_valid')->default(0);
            $table->unsignedInteger('total_rows_duplicate')->default(0);
            $table->unsignedInteger('total_rows_error')->default(0);
            // uploaded / parsed / imported / failed / cancelled
            $table->string('status', 30)->default('uploaded');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_import_batches');
    }
};
