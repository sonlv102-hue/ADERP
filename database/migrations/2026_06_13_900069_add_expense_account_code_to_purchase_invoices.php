<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            // TK chi phí dùng khi hóa đơn không có phiếu nhập kho đi kèm (mua dịch vụ).
            // Mặc định 6422 (QLDN). Kế toán chọn 6421 / 154 / 632 tuỳ loại chi phí.
            $table->string('expense_account_code', 20)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('expense_account_code');
        });
    }
};
