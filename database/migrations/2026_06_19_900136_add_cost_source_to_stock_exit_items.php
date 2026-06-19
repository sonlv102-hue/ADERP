<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->string('cost_source')->nullable()->after('total_cost');
            // giá trị: 'avco' | 'fifo' | 'legacy'
        });
    }

    public function down(): void
    {
        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->dropColumn('cost_source');
        });
    }
};
