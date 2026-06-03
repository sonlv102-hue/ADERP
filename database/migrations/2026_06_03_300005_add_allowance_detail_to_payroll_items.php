<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('allowance_responsibility', 15, 0)->default(0)->after('allowance');
            $table->decimal('allowance_lunch',          15, 0)->default(0)->after('allowance_responsibility');
            $table->decimal('allowance_phone',          15, 0)->default(0)->after('allowance_lunch');
            $table->decimal('allowance_transport',      15, 0)->default(0)->after('allowance_phone');
            $table->decimal('allowance_performance',    15, 0)->default(0)->after('allowance_transport');
            $table->tinyInteger('working_days')->default(26)->after('allowance_performance');
            $table->tinyInteger('standard_days')->default(26)->after('working_days');
            $table->decimal('advance',                  15, 0)->default(0)->after('standard_days');
            $table->boolean('insurance_subject')->default(true)->after('advance');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn([
                'allowance_responsibility', 'allowance_lunch',
                'allowance_phone', 'allowance_transport',
                'allowance_performance', 'working_days',
                'standard_days', 'advance', 'insurance_subject',
            ]);
        });
    }
};
