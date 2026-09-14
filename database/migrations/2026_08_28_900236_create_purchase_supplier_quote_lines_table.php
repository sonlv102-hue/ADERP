<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_supplier_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('purchase_supplier_quotes')->cascadeOnDelete();
            $table->foreignId('comparison_item_id')->constrained('purchase_quote_comparison_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('unit_snapshot', 50)->nullable();
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('net_unit_price', 18, 2)->default(0);
            $table->decimal('vat_percent', 5, 2)->default(0);
            $table->string('delivery_time', 150)->nullable();
            $table->string('warranty', 150)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['quote_id', 'comparison_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_supplier_quote_lines');
    }
};
