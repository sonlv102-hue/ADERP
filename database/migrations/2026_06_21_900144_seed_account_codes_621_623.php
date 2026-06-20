<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $accounts = [
            // TK 621 — Chi phí nguyên liệu, vật liệu trực tiếp
            ['621',  'Chi phí nguyên liệu, vật liệu trực tiếp', 'expense', 'debit', '61',  3, true],
            // TK 623 — Chi phí sử dụng máy thi công (và con)
            ['623',  'Chi phí sử dụng máy thi công',            'expense', 'debit', '61',  3, true],
            ['6231', 'Nhân công điều khiển máy',                 'expense', 'debit', '623', 4, true],
            ['6232', 'Vật liệu cho máy hoạt động',               'expense', 'debit', '623', 4, true],
            ['6234', 'Khấu hao máy thi công',                    'expense', 'debit', '623', 4, true],
            ['6237', 'Dịch vụ thuê máy ngoài',                   'expense', 'debit', '623', 4, true],
            ['6238', 'Chi phí máy thi công khác',                 'expense', 'debit', '623', 4, true],
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
                    'balance_type'   => 'normal',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]],
                ['code'],
                ['name', 'type', 'normal_balance', 'parent_code', 'level', 'is_detail', 'is_active', 'updated_at']
            );
        }
    }

    public function down(): void
    {
        DB::table('account_codes')
            ->whereIn('code', ['621', '623', '6231', '6232', '6234', '6237', '6238'])
            ->delete();
    }
};
