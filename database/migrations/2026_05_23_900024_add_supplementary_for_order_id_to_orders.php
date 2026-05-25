<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('supplementary_for_order_id')
                ->nullable()
                ->after('quotation_id')
                ->constrained('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Order::class, 'supplementary_for_order_id');
            $table->dropColumn('supplementary_for_order_id');
        });
    }
};
