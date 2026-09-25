<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'order_id']);
        });

        // Migrate liên kết PO-SO cũ (cột order_id đơn) sang bảng pivot trước khi cột này bị xóa
        DB::table('purchase_orders')
            ->whereNotNull('order_id')
            ->select('id', 'order_id')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('purchase_order_orders')->insert([
                    'purchase_order_id' => $row->id,
                    'order_id' => $row->order_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_orders');
    }
};
