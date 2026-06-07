<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // KPCĐ tính trên insurance_base (2% mặc định), do NSDLĐ chịu — không trừ vào net_salary
            $table->decimal('trade_union_fee', 15, 0)->default(0)->after('insurance_subject');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('total_trade_union_fee', 15, 0)->default(0)->after('total_insurance_employer');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('trade_union_fee');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('total_trade_union_fee');
        });
    }
};
