<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Thêm batch_type vào period_close_batches
        Schema::table('period_close_batches', function (Blueprint $table) {
            $table->string('batch_type', 20)->default('monthly')->after('status');
            // Nới rộng fiscal_period để hỗ trợ 'year-end-2026' (10 ký tự)
        });

        // 2. Thêm TK 8211 — Chi phí thuế TNDN hiện hành
        // Đảm bảo TK cha 821 tồn tại trước (có thể chỉ có ở seeder, không có ở migration)
        DB::table('account_codes')->insertOrIgnore([
            'code'           => '821',
            'name'           => 'Chi phí thuế thu nhập doanh nghiệp',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'parent_code'    => null,
            'level'          => 2,
            'is_detail'      => true,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        DB::table('account_codes')->insertOrIgnore([
            'code'           => '8211',
            'name'           => 'Chi phí thuế TNDN hiện hành',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'parent_code'    => '821',
            'level'          => 3,
            'is_detail'      => true,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // 3. Thêm accounting_settings mới
        $newSettings = [
            [
                'key'         => 'cit_expense_account',
                'value'       => '821',
                'label'       => 'TK chi phí thuế TNDN',
                'description' => 'Dùng khi hạch toán thuế TNDN tạm tính (Nợ TK này / Có 3334)',
                'group'       => 'period_close',
                'sort_order'  => 30,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'period_close_prior_year_account',
                'value'       => '4211',
                'label'       => 'TK LNST chưa phân phối năm trước',
                'description' => 'Nhận kết chuyển từ 4212 khi chuyển sang năm mới',
                'group'       => 'period_close',
                'sort_order'  => 40,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        foreach ($newSettings as $setting) {
            DB::table('accounting_settings')
                ->where('key', $setting['key'])
                ->exists() ?: DB::table('accounting_settings')->insert($setting);
        }
    }

    public function down(): void
    {
        Schema::table('period_close_batches', function (Blueprint $table) {
            $table->dropColumn('batch_type');
        });

        DB::table('account_codes')->where('code', '8211')->delete();

        DB::table('accounting_settings')
            ->whereIn('key', ['cit_expense_account', 'period_close_prior_year_account'])
            ->delete();
    }
};
