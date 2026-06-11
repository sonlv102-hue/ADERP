<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('payable_account_code', 10)
                ->nullable()
                ->after('notes');

            $table->foreign('payable_account_code')
                ->references('code')
                ->on('account_codes')
                ->restrictOnDelete();
        });

        // Seed existing suppliers với 3311 (NCC trong nước — mặc định)
        // Kế toán có thể đổi sang 3312/3318 sau khi vào form sửa NCC
        DB::table('suppliers')->whereNull('payable_account_code')
            ->update(['payable_account_code' => '3311']);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign(['payable_account_code']);
            $table->dropColumn('payable_account_code');
        });
    }
};
