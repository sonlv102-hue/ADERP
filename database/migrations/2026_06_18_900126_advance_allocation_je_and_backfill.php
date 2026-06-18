<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Thêm journal_entry_id vào supplier_advance_allocations để lưu JE đối trừ
        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->after('status')
                  ->constrained('journal_entries')
                  ->nullOnDelete();
        });

        // 2. Backfill account_code trên supplier_opening_advances:
        //    prepayment records hiện đang lưu '3311' sai → đổi sang '331UT'
        //    (opening_balance records giữ nguyên vì dùng TK per-supplier từ getPayableAccount())
        DB::table('supplier_opening_advances')
            ->where('advance_type', 'prepayment')
            ->where('account_code', '3311')
            ->update(['account_code' => '331UT', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Revert backfill
        DB::table('supplier_opening_advances')
            ->where('advance_type', 'prepayment')
            ->where('account_code', '331UT')
            ->update(['account_code' => '3311', 'updated_at' => now()]);

        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
