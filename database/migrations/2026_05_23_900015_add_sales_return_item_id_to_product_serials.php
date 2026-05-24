<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreignId('sales_return_item_id')
                ->nullable()
                ->after('stock_transfer_item_id')
                ->constrained('sales_return_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropForeign(['sales_return_item_id']);
            $table->dropColumn('sales_return_item_id');
        });
    }
};
