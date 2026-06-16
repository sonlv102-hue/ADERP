<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table) {
            // Phần khấu hao không được trừ thuế TNDN (xe ô tô ≤9 chỗ vượt 1,6 tỷ)
            // = total_depreciation - (tax_deductible_cost / useful_life_months)
            // Lưu để phục vụ báo cáo quyết toán thuế
            $table->decimal('non_deductible_amount', 15, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table) {
            $table->dropColumn('non_deductible_amount');
        });
    }
};
