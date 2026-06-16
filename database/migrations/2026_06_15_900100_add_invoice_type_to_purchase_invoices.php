<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Thêm invoice_type vào purchase_invoices để phân loại hóa đơn đầu vào theo TT133.
     *
     * Khi null (các hóa đơn cũ): hệ thống dùng isGoodsPurchase() logic cũ làm fallback.
     * Khi set: routing kế toán theo đúng loại (hàng hóa, dịch vụ, dự án, TSCĐ, v.v.)
     */
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('invoice_type', 30)->nullable()->after('expense_account_code');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('invoice_type');
        });
    }
};
