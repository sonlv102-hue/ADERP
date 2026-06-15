<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->string('account_code', 20)->nullable()->after('type')
                ->comment('TK kế toán chi tiết cho quỹ này (VD: 1111, 1121-VCB). Dùng khi luân chuyển quỹ.');
        });
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn('account_code');
        });
    }
};
