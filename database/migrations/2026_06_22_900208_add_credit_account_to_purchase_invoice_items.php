<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Seed TK 331x nếu chưa có (upsert an toàn)
        $accounts = [
            ['3311', 'Phải trả nhà cung cấp hàng hóa', 'liability', 'credit', '331', 4, true],
            ['3312', 'Phải trả nhà cung cấp dịch vụ',  'liability', 'credit', '331', 4, true],
            ['3318', 'Phải trả người bán khác',         'liability', 'credit', '331', 4, true],
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

        // 2. Thêm TK AccountingSettings cho NCC dịch vụ mặc định
        DB::table('accounting_settings')->upsert(
            [[
                'key'         => 'service_ap_account',
                'value'       => '3312',
                'label'       => 'TK phải trả NCC dịch vụ',
                'description' => 'TK Có mặc định khi ghi nhận hóa đơn dịch vụ / thuê ngoài',
                'group'       => 'ar_ap',
                'sort_order'  => 41,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]],
            ['key'],
            ['value', 'label', 'description', 'group', 'sort_order', 'updated_at']
        );

        // 3. Thêm credit_account_code vào purchase_invoice_items
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->string('credit_account_code', 20)
                ->nullable()
                ->after('account_code')
                ->comment('TK Có cho dòng này; null = tự động theo loại hóa đơn');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn('credit_account_code');
        });

        DB::table('accounting_settings')->where('key', 'service_ap_account')->delete();

        DB::table('account_codes')->whereIn('code', ['3311', '3312', '3318'])
            ->whereNotExists(function ($q) {
                // Chỉ xóa nếu không có JE nào đang dùng
                $q->select(DB::raw(1))
                  ->from('journal_entry_lines')
                  ->whereIn('account_code', ['3311', '3312', '3318']);
            })
            ->delete();
    }
};
