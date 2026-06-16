<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // --- Sửa TK tổng hợp → TK chi tiết cấp cuối ---

        // 334 (tổng hợp) → 3341 (Lương — chi tiết)
        DB::table('accounting_settings')
            ->where('key', 'salary_payable_account')
            ->update([
                'value'       => '3341',
                'label'       => 'TK phải trả NLĐ (lương)',
                'description' => 'Ghi có khi ghi nhận lương; ghi nợ khi thanh toán lương (3341)',
                'updated_at'  => now(),
            ]);

        // 3383 (tổng hợp) → 33831 (BHXH NSDLĐ — chi tiết)
        DB::table('accounting_settings')
            ->where('key', 'bhxh_payable_account')
            ->update([
                'value'       => '33831',
                'label'       => 'TK BHXH NSDLĐ chịu',
                'description' => 'Phần công ty đóng BHXH (33831)',
                'updated_at'  => now(),
            ]);

        // 3384 (tổng hợp) → 33841 (BHYT NSDLĐ — chi tiết)
        DB::table('accounting_settings')
            ->where('key', 'bhyt_payable_account')
            ->update([
                'value'       => '33841',
                'label'       => 'TK BHYT NSDLĐ chịu',
                'description' => 'Phần công ty đóng BHYT (33841)',
                'updated_at'  => now(),
            ]);

        // 3382 (tổng hợp) → 33821 (KPCĐ NSDLĐ — chi tiết)
        DB::table('accounting_settings')
            ->where('key', 'union_fee_payable_account')
            ->update([
                'value'       => '33821',
                'label'       => 'TK KPCĐ NSDLĐ chịu',
                'description' => 'Kinh phí công đoàn do doanh nghiệp trích nộp (33821)',
                'updated_at'  => now(),
            ]);

        // --- Thêm TK phần NLĐ đóng (cần để tách riêng Dr 3341 / Cr 338x) ---
        DB::table('accounting_settings')->insertOrIgnore([
            [
                'key'         => 'bhxh_employee_account',
                'value'       => '33832',
                'label'       => 'TK BHXH NLĐ đóng',
                'description' => 'Phần nhân viên đóng BHXH (khấu trừ từ 3341) — 33832',
                'group'       => 'payroll',
                'sort_order'  => 32,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'bhyt_employee_account',
                'value'       => '33842',
                'label'       => 'TK BHYT NLĐ đóng',
                'description' => 'Phần nhân viên đóng BHYT (khấu trừ từ 3341) — 33842',
                'group'       => 'payroll',
                'sort_order'  => 42,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('accounting_settings')
            ->where('key', 'salary_payable_account')
            ->update(['value' => '334', 'label' => 'TK phải trả người lao động', 'description' => 'Tổng hợp lương phải trả nhân viên', 'updated_at' => now()]);

        DB::table('accounting_settings')
            ->where('key', 'bhxh_payable_account')
            ->update(['value' => '3383', 'label' => 'TK BHXH phải nộp', 'description' => 'BHXH nhân viên + công ty', 'updated_at' => now()]);

        DB::table('accounting_settings')
            ->where('key', 'bhyt_payable_account')
            ->update(['value' => '3384', 'label' => 'TK BHYT phải nộp', 'description' => 'BHYT nhân viên + công ty', 'updated_at' => now()]);

        DB::table('accounting_settings')
            ->where('key', 'union_fee_payable_account')
            ->update(['value' => '3382', 'label' => 'TK kinh phí công đoàn', 'description' => 'KPCĐ phải nộp', 'updated_at' => now()]);

        DB::table('accounting_settings')
            ->whereIn('key', ['bhxh_employee_account', 'bhyt_employee_account'])
            ->delete();
    }
};
