<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phiếu nhập kho thủ công (không qua đơn mua hàng): lưu TK đối ứng bên Có.
 * NULL = phiếu nhập từ PO (TK Có lấy theo NCC của PO như cũ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->string('credit_account_code', 20)->nullable()->after('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropColumn('credit_account_code');
        });
    }
};
