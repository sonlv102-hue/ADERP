<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('qty_on_hand', 15, 3)->default(0);
            $table->decimal('value_on_hand', 18, 2)->default(0);
            $table->decimal('avg_cost', 18, 6)->default(0);
            $table->foreignId('last_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->nullOnDelete();
            $table->string('initialized_from')->nullable();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
