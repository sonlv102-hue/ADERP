<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('base_salary', 15, 2)->default(0)->after('hire_date');
            $table->decimal('allowance', 15, 2)->default(0)->after('base_salary');
            $table->tinyInteger('dependents_count')->default(0)->after('allowance');
            $table->string('pit_tax_code', 20)->nullable()->after('dependents_count');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['base_salary', 'allowance', 'dependents_count', 'pit_tax_code']);
        });
    }
};
