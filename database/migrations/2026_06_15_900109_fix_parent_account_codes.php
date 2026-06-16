<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sửa dữ liệu cũ: bank_accounts.account_code = '112' → '1121'
        DB::table('bank_accounts')
            ->where('account_code', '112')
            ->update(['account_code' => '1121']);

        // Sửa dữ liệu cũ: funds.account_code = '112' → '1121'
        DB::table('funds')
            ->where('account_code', '112')
            ->update(['account_code' => '1121']);

        // Sửa accounting_settings nếu bank_account = '112'
        DB::table('accounting_settings')
            ->where('key', 'bank_account')
            ->where('value', '112')
            ->update(['value' => '1121']);
    }

    public function down(): void
    {
        // không rollback dữ liệu — không an toàn để hoàn tác
    }
};
