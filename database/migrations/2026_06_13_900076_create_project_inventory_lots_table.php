<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_inventory_lots', function (Blueprint $table) {
            $table->id();

            // Dimensions
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // Source traceability: từ dòng nào nhập, từ PO nào
            $table->foreignId('stock_entry_id')->constrained('stock_entries')->restrictOnDelete();
            $table->foreignId('stock_entry_item_id')->constrained('stock_entry_items')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();

            // Quantity tracking
            $table->decimal('received_qty', 12, 3);
            $table->decimal('issued_qty', 12, 3)->default(0);

            // Cost
            $table->decimal('unit_cost', 18, 2);

            // FIFO key
            $table->timestamp('received_at');

            // Status: active (còn tồn), depleted (hết), cancelled (hủy entry)
            $table->string('status', 20)->default('active');

            $table->timestamps();
        });

        // PostgreSQL: kiểm tra issued_qty không vượt received_qty
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE project_inventory_lots
                ADD CONSTRAINT chk_pil_issued_lte_received
                CHECK (issued_qty <= received_qty)
            ');
            DB::statement("
                ALTER TABLE project_inventory_lots
                ADD CONSTRAINT chk_pil_status
                CHECK (status IN ('active', 'depleted', 'cancelled'))
            ");
        }

        // Index cho FIFO query: project + product + warehouse → order by received_at
        DB::statement('
            CREATE INDEX idx_pil_project_product_wh
            ON project_inventory_lots(project_id, product_id, warehouse_id, status, received_at)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_inventory_lots');
    }
};
