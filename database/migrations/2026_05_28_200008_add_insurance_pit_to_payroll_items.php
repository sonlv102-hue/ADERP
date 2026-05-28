<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('gross_salary', 15, 0)->default(0)->after('bonus');
            $table->decimal('insurance_base', 15, 0)->default(0)->after('gross_salary');
            $table->decimal('bhxh_employee', 15, 0)->default(0)->after('insurance_base');
            $table->decimal('bhyt_employee', 15, 0)->default(0)->after('bhxh_employee');
            $table->decimal('bhtn_employee', 15, 0)->default(0)->after('bhyt_employee');
            $table->decimal('bhxh_employer', 15, 0)->default(0)->after('bhtn_employee');
            $table->decimal('bhyt_employer', 15, 0)->default(0)->after('bhxh_employer');
            $table->decimal('bhtn_employer', 15, 0)->default(0)->after('bhyt_employer');
            $table->decimal('pit', 15, 0)->default(0)->after('bhtn_employer');
            $table->tinyInteger('dependents_count')->default(0)->after('pit');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn([
                'gross_salary', 'insurance_base',
                'bhxh_employee', 'bhyt_employee', 'bhtn_employee',
                'bhxh_employer', 'bhyt_employer', 'bhtn_employer',
                'pit', 'dependents_count',
            ]);
        });
    }
};
