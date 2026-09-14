<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quote_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comparison_item_id')->unique()->constrained('purchase_quote_comparison_items')->cascadeOnDelete();
            $table->foreignId('quote_line_id')->nullable()->constrained('purchase_supplier_quote_lines')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->decimal('selected_unit_price', 18, 2)->default(0);
            $table->boolean('is_lowest_price')->default(false);
            $table->string('selection_reason', 40)->nullable(); // faster_delivery|better_payment_terms|better_quality|in_stock|other
            $table->text('selection_note')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quote_selections');
    }
};
