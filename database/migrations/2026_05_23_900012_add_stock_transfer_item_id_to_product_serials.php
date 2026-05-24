<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreignId('stock_transfer_item_id')
                ->nullable()
                ->after('stock_exit_item_id')
                ->constrained('stock_transfer_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropForeign(['stock_transfer_item_id']);
            $table->dropColumn('stock_transfer_item_id');
        });
    }
};
