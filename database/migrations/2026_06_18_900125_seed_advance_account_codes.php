<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Thêm TK ứng trước AR/AP — tách bạch với TK công nợ thường
        $accounts = [
            // 131UT — Người mua trả tiền trước / KH ứng trước (Dư Có, tính chất nợ ngắn hạn)
            ['131UT', 'Người mua trả tiền trước', 'liability', 'credit', '131', 4, true],
            // 331UT — Trả trước cho người bán / trả trước NCC (Dư Nợ, tính chất tài sản ngắn hạn)
            ['331UT', 'Trả trước cho người bán',  'asset',     'debit',  '331', 4, true],
        ];

        foreach ($accounts as [$code, $name, $type, $balance, $parent, $level, $isDetail]) {
            DB::table('account_codes')->upsert(
                [[
                    'code'           => $code,
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => $balance,
                    'parent_code'    => $parent,
                    'level'          => $level,
                    'is_detail'      => $isDetail,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]],
                ['code'],
                ['name', 'type', 'normal_balance', 'parent_code', 'level', 'is_detail', 'is_active', 'updated_at']
            );
        }

        // Không thay đổi is_detail của '131'/'331' — chúng vẫn có thể dùng trực tiếp trong JE
        // (customer->getReceivableAccount() và supplier->getPayableAccount() trả về TK gốc).

        // Thêm AccountingSettings keys cho TK ứng trước
        $settings = [
            [
                'key'         => 'customer_advance_account',
                'value'       => '131UT',
                'label'       => 'TK khách hàng ứng trước',
                'description' => 'TK ghi Có khi khách hàng thanh toán trước (131UT)',
                'group'       => 'ar_ap',
                'sort_order'  => 51,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'supplier_advance_account',
                'value'       => '331UT',
                'label'       => 'TK trả trước nhà cung cấp',
                'description' => 'TK ghi Nợ khi trả trước cho NCC (331UT)',
                'group'       => 'ar_ap',
                'sort_order'  => 52,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('accounting_settings')->upsert(
                [$setting],
                ['key'],
                ['value', 'label', 'description', 'group', 'sort_order', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        DB::table('account_codes')->whereIn('code', ['131UT', '331UT'])->delete();
        DB::table('accounting_settings')->whereIn('key', ['customer_advance_account', 'supplier_advance_account'])->delete();

        // Restore 131 + 331 về is_detail=true nếu không còn con nào khác
        // (chỉ rollback an toàn nếu không có JE reference — để đơn giản, không auto-restore)
    }
};
