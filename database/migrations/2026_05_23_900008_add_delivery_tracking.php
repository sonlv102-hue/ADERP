<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('customer_id')
                ->constrained('orders')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('delivered_quantity', 10, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('delivered_quantity');
        });
    }
};
