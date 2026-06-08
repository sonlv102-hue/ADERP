<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_codes', function (Blueprint $table) {
            $table->enum('balance_type', ['normal', 'both'])->default('normal')->after('normal_balance');
        });

        // TK 131 và 331 là tài khoản lưỡng tính — có thể có số dư cả Nợ lẫn Có
        DB::table('account_codes')
            ->whereIn('code', ['131', '331'])
            ->update(['balance_type' => 'both', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('account_codes')
            ->whereIn('code', ['131', '331'])
            ->update(['balance_type' => 'normal', 'updated_at' => now()]);

        Schema::table('account_codes', function (Blueprint $table) {
            $table->dropColumn('balance_type');
        });
    }
};
