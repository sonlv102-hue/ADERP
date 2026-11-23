<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiền VAT kế toán tự nhập trên phiếu nhập thủ công (theo số trên hóa đơn).
 * NULL = tự tính quantity × unit_price × tax_rate / 100 như cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 15, 2)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });
    }
};
