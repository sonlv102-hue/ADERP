<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->boolean('union_fee_include')->nullable()->after('total_trade_union_fee');
            $table->foreignId('union_fee_confirmed_by')->nullable()->constrained('users')->nullOnDelete()->after('union_fee_include');
            $table->timestamp('union_fee_confirmed_at')->nullable()->after('union_fee_confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropForeign(['union_fee_confirmed_by']);
            $table->dropColumn(['union_fee_include', 'union_fee_confirmed_by', 'union_fee_confirmed_at']);
        });
    }
};
