<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cột điều chỉnh trên từng dòng lương nhân viên
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->decimal('adjustment_amount', 18, 2)->default(0)->after('net_salary');
            $table->text('adjustment_reason')->nullable()->after('adjustment_amount');
            $table->boolean('adjustment_taxable')->default(true)->after('adjustment_reason');
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete()->after('adjustment_taxable');
            $table->timestampTz('adjusted_at')->nullable()->after('adjusted_by');
        });

        // Tổng điều chỉnh trên bảng lương (để hiển thị tổng cộng)
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('total_adjustment', 18, 2)->default(0)->after('total_net_salary');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropForeign(['adjusted_by']);
            $table->dropColumn(['adjustment_amount', 'adjustment_reason', 'adjustment_taxable', 'adjusted_by', 'adjusted_at']);
        });
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('total_adjustment');
        });
    }
};
