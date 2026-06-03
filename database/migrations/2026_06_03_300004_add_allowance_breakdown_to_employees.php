<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('allowance_responsibility', 15, 0)->default(0)->after('allowance');
            $table->decimal('allowance_lunch',          15, 0)->default(0)->after('allowance_responsibility');
            $table->decimal('allowance_phone',          15, 0)->default(0)->after('allowance_lunch');
            $table->decimal('allowance_transport',      15, 0)->default(0)->after('allowance_phone');
            $table->boolean('insurance_subject')->default(true)->after('allowance_transport');
            $table->tinyInteger('standard_days')->default(26)->after('insurance_subject');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'allowance_responsibility', 'allowance_lunch',
                'allowance_phone', 'allowance_transport',
                'insurance_subject', 'standard_days',
            ]);
        });
    }
};
