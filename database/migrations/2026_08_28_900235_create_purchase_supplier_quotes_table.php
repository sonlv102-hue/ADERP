<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comparison_id')->constrained('purchase_quote_comparisons')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('quote_no', 100)->nullable();
            $table->date('quote_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('currency', 10)->default('VND');
            $table->string('payment_terms', 255)->nullable();
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->text('note')->nullable();

            $table->unsignedInteger('version_no')->default(1);
            $table->boolean('is_active_version')->default(true);

            $table->string('original_filename', 255)->nullable();
            $table->string('stored_file_path', 500)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['comparison_id', 'supplier_id', 'is_active_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_supplier_quotes');
    }
};
