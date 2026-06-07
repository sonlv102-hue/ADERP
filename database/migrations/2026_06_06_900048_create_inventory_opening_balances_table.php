<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7);            // YYYY-MM
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 18, 2)->default(0);  // đơn giá vốn đầu kỳ
            $table->decimal('total_cost', 18, 2)->default(0); // = quantity * unit_cost
            $table->text('note')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->unique(['period', 'warehouse_id', 'product_id'], 'uniq_inv_ob_period_wh_prod');
            $table->index(['period', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_opening_balances');
    }
};
