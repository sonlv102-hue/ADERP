<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quote_comparison_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comparison_id')->constrained('purchase_quote_comparisons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('product_code_snapshot', 50);
            $table->string('product_name_snapshot', 255);
            $table->string('unit_snapshot', 50)->nullable();
            $table->string('specification', 255)->nullable();
            $table->decimal('requested_qty', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['comparison_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quote_comparison_items');
    }
};
