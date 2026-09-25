<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }

    // Không khôi phục dữ liệu cũ khi rollback — liên kết PO-SO đã chuyển sang bảng purchase_order_orders.
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('project_id')
                  ->constrained('orders')->nullOnDelete();
        });
    }
};
