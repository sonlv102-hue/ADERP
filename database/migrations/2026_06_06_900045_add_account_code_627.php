<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bổ sung các TK cần thiết vào chart of accounts.
        // Upsert theo thứ tự cha → con để đảm bảo FK tự-tham chiếu không fail trên SQLite.
        // Idempotent: chạy lại không phá dữ liệu hiện có.
        // Thêm cả TK asset/liability cơ bản để test môi trường SQLite hoạt động không cần seeder.
        $accounts = [
            // ── Tài sản ngắn hạn ──
            ['1',    'Tài sản ngắn hạn',                                   'asset',     'debit',  null,  1, false],
            ['11',   'Tiền',                                                'asset',     'debit',  '1',   2, false],
            ['111',  'Tiền mặt',                                            'asset',     'debit',  '11',  3, false],
            ['112',  'Tiền gửi ngân hàng',                                  'asset',     'debit',  '11',  3, false],
            ['13',   'Các khoản phải thu',                                  'asset',     'debit',  '1',   2, false],
            ['131',  'Phải thu của khách hàng',                             'asset',     'debit',  '13',  3, true],
            // ── Phải trả ngắn hạn ──
            ['3',    'Nợ phải trả',                                         'liability', 'credit', null,  1, false],
            ['33',   'Phải trả người lao động và các khoản khấu trừ',       'liability', 'credit', '3',   2, false],
            ['331',  'Phải trả người bán',                                   'liability', 'credit', '3',   2, true],
            ['334',  'Phải trả người lao động',                             'liability', 'credit', '33',  3, true],
            ['3335', 'Thuế thu nhập cá nhân',                               'liability', 'credit', '33',  3, true],
            ['3382', 'Kinh phí công đoàn',                                  'liability', 'credit', '33',  3, true],
            ['3383', 'Bảo hiểm xã hội',                                     'liability', 'credit', '33',  3, true],
            ['3384', 'Bảo hiểm y tế',                                       'liability', 'credit', '33',  3, true],
            ['3385', 'Bảo hiểm thất nghiệp',                                'liability', 'credit', '33',  3, true],
            ['33311','Thuế GTGT đầu ra',                                    'liability', 'credit', '33',  3, true],
            // ── Doanh thu ──
            ['5',    'Doanh thu',                                            'revenue',   'credit', null,  1, false],
            ['51',   'Doanh thu bán hàng',                                  'revenue',   'credit', '5',   2, false],
            ['511',  'Doanh thu bán hàng và cung cấp dịch vụ',              'revenue',   'credit', '51',  3, false],
            ['5111', 'Doanh thu bán hàng hóa',                              'revenue',   'credit', '511', 4, true],
            ['5113', 'Doanh thu cung cấp dịch vụ',                          'revenue',   'credit', '511', 4, true],
            // ── Chi phí sản xuất kinh doanh ──
            ['6',    'Chi phí sản xuất kinh doanh',                         'expense',   'debit',  null,  1, false],
            ['61',   'Giá vốn và chi phí sản xuất',                         'expense',   'debit',  '6',   2, false],
            ['631',  'Giá thành sản xuất',                                  'expense',   'debit',  '61',  3, true],
            ['632',  'Giá vốn hàng bán',                                    'expense',   'debit',  '61',  3, true],
            ['627',  'Chi phí sản xuất chung',                              'expense',   'debit',  '61',  3, true],
            ['642',  'Chi phí quản lý kinh doanh',                          'expense',   'debit',  '6',   2, false],
            ['6421', 'Chi phí bán hàng',                                    'expense',   'debit',  '642', 3, true],
            ['6422', 'Chi phí quản lý doanh nghiệp',                        'expense',   'debit',  '642', 3, true],
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
        DB::table('account_codes')->where('code', '627')->update(['is_active' => false]);
    }
};
