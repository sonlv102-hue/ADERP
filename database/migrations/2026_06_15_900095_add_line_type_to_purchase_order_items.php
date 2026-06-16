<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // goods=hàng hóa bán lại(1561) | material=NVL(1521) | tool=CCDC(1531)
            // service=dịch vụ/CP(6421/6422) | fixed_asset=TSCĐ(2111)
            $table->string('line_type', 30)->default('goods')->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('line_type');
        });
    }
};
