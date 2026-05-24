<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->foreignId('stock_exit_item_id')
                ->nullable()
                ->after('stock_entry_item_id')
                ->constrained('stock_exit_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_exit_item_id');
        });
    }
};
