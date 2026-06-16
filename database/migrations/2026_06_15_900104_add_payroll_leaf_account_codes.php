<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Thêm TK chi tiết cho nhóm Lương & Bảo hiểm
        // Đồng thời đánh dấu TK cha là_detail=false (đúng với production DB)
        // Idempotent: upsert an toàn khi chạy lại.

        $accounts = [
            // ─── TK 334 → cha, thêm con ───
            ['334',  'Phải trả người lao động',         'liability', 'credit', '33',   3, false],
            ['3341', 'Lương',                             'liability', 'credit', '334',  4, true],
            ['3342', 'Thưởng',                            'liability', 'credit', '334',  4, true],

            // ─── TK 3382 → cha, thêm con ───
            ['3382',  'Kinh phí công đoàn (KPCĐ)',       'liability', 'credit', '33',   3, false],
            ['33821', 'KPCĐ doanh nghiệp chịu',          'liability', 'credit', '3382', 4, true],
            ['33822', 'KPCĐ người lao động đóng',        'liability', 'credit', '3382', 4, true],

            // ─── TK 3383 → cha, thêm con ───
            ['3383',  'Bảo hiểm xã hội (BHXH)',          'liability', 'credit', '33',   3, false],
            ['33831', 'BHXH doanh nghiệp chịu',          'liability', 'credit', '3383', 4, true],
            ['33832', 'BHXH người lao động đóng',        'liability', 'credit', '3383', 4, true],

            // ─── TK 3384 → cha, thêm con ───
            ['3384',  'Bảo hiểm y tế (BHYT)',            'liability', 'credit', '33',   3, false],
            ['33841', 'BHYT doanh nghiệp chịu',          'liability', 'credit', '3384', 4, true],
            ['33842', 'BHYT người lao động đóng',        'liability', 'credit', '3384', 4, true],
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
    }

    public function down(): void
    {
        // Xoá các TK con và đặt lại cha thành is_detail=true (rollback về trạng thái 900045)
        DB::table('account_codes')
            ->whereIn('code', ['3341','3342','33821','33822','33831','33832','33841','33842'])
            ->delete();

        DB::table('account_codes')
            ->whereIn('code', ['334','3382','3383','3384'])
            ->update(['is_detail' => true, 'updated_at' => now()]);
    }
};
