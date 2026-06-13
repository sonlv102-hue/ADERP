<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_exit_item_lot_allocations', function (Blueprint $table) {
            $table->id();

            // Parent references
            $table->foreignId('stock_exit_id')->constrained('stock_exits')->restrictOnDelete();
            $table->foreignId('stock_exit_item_id')->constrained('stock_exit_items')->restrictOnDelete();
            $table->foreignId('project_inventory_lot_id')->constrained('project_inventory_lots')->restrictOnDelete();

            // Denormalized dimensions — giúp query báo cáo không cần JOIN ngược
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // Allocation detail
            $table->decimal('allocated_qty', 12, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('amount', 18, 2);  // = allocated_qty * unit_cost

            // Soft-void khi cancel exit (không delete để giữ audit trail)
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();
        });

        // Indexes
        DB::statement('CREATE INDEX idx_seila_exit_item ON stock_exit_item_lot_allocations(stock_exit_item_id)');
        DB::statement('CREATE INDEX idx_seila_lot       ON stock_exit_item_lot_allocations(project_inventory_lot_id)');
        DB::statement('CREATE INDEX idx_seila_exit      ON stock_exit_item_lot_allocations(stock_exit_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_exit_item_lot_allocations');
    }
};
