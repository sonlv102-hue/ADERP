<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // products.revenue_account_code     — override tường minh theo sản phẩm
    // product_categories.revenue_account_code — mặc định theo danh mục
    // invoices.revenue_account_code     — cho standalone invoice không gắn order
    //
    // Thứ tự ưu tiên khi snapshot vào order_items.revenue_account_code:
    //   products.revenue_account_code
    //   → product_categories.revenue_account_code
    //   → item_type mapping (goods→5111, service→5113)
    //   → null (CẦN KẾ TOÁN XÁC NHẬN)
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('revenue_account_code', 10)->nullable()
                ->after('item_type')
                ->comment('Override tài khoản doanh thu; để null = dùng theo category hoặc item_type');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('revenue_account_code', 10)->nullable()
                ->after('description')
                ->comment('Mặc định tài khoản doanh thu cho sản phẩm trong danh mục này');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('revenue_account_code', 10)->nullable()
                ->after('notes')
                ->comment('Tài khoản doanh thu cho standalone invoice không gắn order');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('revenue_account_code');
        });
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('revenue_account_code');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('revenue_account_code');
        });
    }
};
