<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Đổi tên TK ứng trước từ mã cũ (3311UT / 1311UT) sang mã chuẩn (331UT / 131UT).
     * Migration 900125 đã seed 331UT + 131UT. Migration này chỉ dọn dẹp mã cũ trên các
     * môi trường đã có dữ liệu thủ công trước khi 900125 chạy.
     */
    public function up(): void
    {
        // AP advance: 3311UT → 331UT
        if (DB::table('account_codes')->where('code', '3311UT')->exists()) {
            DB::table('journal_entry_lines')
                ->where('account_code', '3311UT')
                ->update(['account_code' => '331UT', 'updated_at' => now()]);

            DB::table('supplier_opening_advances')
                ->where('account_code', '3311UT')
                ->update(['account_code' => '331UT', 'updated_at' => now()]);

            DB::table('account_codes')->where('code', '3311UT')->delete();
        }

        // AR advance: 1311UT → 131UT
        if (DB::table('account_codes')->where('code', '1311UT')->exists()) {
            DB::table('journal_entry_lines')
                ->where('account_code', '1311UT')
                ->update(['account_code' => '131UT', 'updated_at' => now()]);

            DB::table('account_codes')->where('code', '1311UT')->delete();
        }
    }

    public function down(): void
    {
        // Không rollback vì 3311UT/1311UT là dữ liệu thủ công cũ, không cần restore.
    }
};
