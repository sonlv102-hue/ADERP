<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-line order_item tracking cho sale_delivery
        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('product_id')
                ->constrained('order_items')
                ->nullOnDelete();
        });

        // Junction table: một phiếu xuất có thể liên kết nhiều đơn mua (project_cost)
        Schema::create('stock_exit_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_exit_id')
                ->constrained('stock_exits')
                ->cascadeOnDelete();
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['stock_exit_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_exit_purchase_orders');

        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });
    }
};
