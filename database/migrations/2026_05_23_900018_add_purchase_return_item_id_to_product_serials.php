<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreignId('purchase_return_item_id')
                ->nullable()
                ->constrained('purchase_return_items')
                ->nullOnDelete()
                ->after('stock_transfer_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropForeign(['purchase_return_item_id']);
            $table->dropColumn('purchase_return_item_id');
        });
    }
};
