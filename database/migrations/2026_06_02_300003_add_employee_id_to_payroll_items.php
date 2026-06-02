<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Add employee_id for new payrolls (from Cán bộ CNV)
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete()->after('payroll_id');
            // Make user_id nullable for backward compatibility with old records
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
