<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounting_settings')->insertOrIgnore([
            [
                'key'         => 'vat_output_account',
                'value'       => '33311',
                'label'       => 'TK thuế GTGT đầu ra',
                'description' => 'Ghi có khi xuất hóa đơn bán hàng; ghi nợ khi kê khai nộp thuế',
                'group'       => 'revenue',
                'sort_order'  => 30,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'key'         => 'depreciation_expense_account',
                'value'       => '6421',
                'label'       => 'TK chi phí khấu hao TSCĐ (mặc định)',
                'description' => 'Fallback khi tài sản không cấu hình depreciation_expense_account_code riêng',
                'group'       => 'cogs',
                'sort_order'  => 50,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('accounting_settings')
            ->whereIn('key', ['vat_output_account', 'depreciation_expense_account'])
            ->delete();
    }
};
