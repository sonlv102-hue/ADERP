<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            // Người sử dụng và mục đích (TT45 yêu cầu ghi rõ bộ phận, người, mục đích)
            $table->string('responsible_user', 100)->nullable()->after('department');
            $table->text('usage_purpose')->nullable()->after('responsible_user');

            // Phân loại thuế TNDN
            $table->boolean('is_for_business')->default(true)->after('usage_purpose');
            $table->boolean('is_sedan_under_9_seats')->default(false)->after('is_for_business');

            // Nguyên giá tính chi phí được trừ thuế TNDN
            // Với xe ô tô ≤9 chỗ: min(acquisition_cost, 1_600_000_000)
            // Null = bằng acquisition_cost (không giới hạn)
            $table->decimal('tax_deductible_cost', 15, 2)->nullable()->after('is_sedan_under_9_seats');
        });

        // Data fix: cập nhật TK chi phí khấu hao cho TSCĐ-0001 và TSCĐ-0002
        // TSCĐ-0001: xe giám đốc → Nợ 6422 (chi phí QLDN)
        // TSCĐ-0002: xe kinh doanh → Nợ 641 (chi phí bán hàng)
        DB::table('fixed_assets')
            ->where('code', 'TSCĐ-0001')
            ->update([
                'depreciation_expense_account_code' => '6422',
                'responsible_user' => 'Giám đốc',
                'usage_purpose'    => 'Quản lý, điều hành doanh nghiệp',
                'is_for_business'  => true,
                'is_sedan_under_9_seats' => true,
            ]);

        DB::table('fixed_assets')
            ->where('code', 'TSCĐ-0002')
            ->update([
                'depreciation_expense_account_code' => '641',
                'responsible_user' => 'Bộ phận kinh doanh',
                'usage_purpose'    => 'Bán hàng, gặp khách hàng, đi thị trường',
                'is_for_business'  => true,
                'is_sedan_under_9_seats' => true,
            ]);

        // Backfill tax_deductible_cost cho tất cả xe ≤9 chỗ đã đánh dấu
        // Dùng CASE WHEN để tương thích SQLite (tests) lẫn PostgreSQL (production)
        DB::statement(
            "UPDATE fixed_assets
             SET tax_deductible_cost = CASE
                 WHEN acquisition_cost < 1600000000 THEN acquisition_cost
                 ELSE 1600000000
             END
             WHERE is_sedan_under_9_seats = true AND tax_deductible_cost IS NULL"
        );
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn([
                'responsible_user',
                'usage_purpose',
                'is_for_business',
                'is_sedan_under_9_seats',
                'tax_deductible_cost',
            ]);
        });
    }
};
