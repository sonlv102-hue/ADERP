<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quote_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comparison_id')->constrained('purchase_quote_comparisons')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('purchase_supplier_quotes')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('file_hash', 64)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('import_status', 20)->default('previewed'); // previewed | confirmed | failed
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->jsonb('error_detail_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quote_import_logs');
    }
};
