<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('total_gross', 15, 0)->default(0)->after('total_bonus');
            $table->decimal('total_insurance_employee', 15, 0)->default(0)->after('total_gross');
            $table->decimal('total_insurance_employer', 15, 0)->default(0)->after('total_insurance_employee');
            $table->decimal('total_pit', 15, 0)->default(0)->after('total_insurance_employer');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['total_gross', 'total_insurance_employee', 'total_insurance_employer', 'total_pit']);
        });
    }
};
