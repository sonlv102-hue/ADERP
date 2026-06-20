<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            // Đơn mua nguồn — nullable vì: (a) không phải exit nào cũng có PO,
            // (b) khi 1 exit lấy từ nhiều PO thì header để null, trace qua lot_allocations.
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('order_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
