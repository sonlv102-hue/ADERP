<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // TK kho mặc định cho sản phẩm (156/1561 hàng hóa, 152 NVL/vật tư, 153 CCDC)
            // Ưu tiên: product.inventory_account → category.inventory_account → system default
            $table->string('inventory_account', 20)->nullable()->after('item_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('inventory_account');
        });
    }
};
