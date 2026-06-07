<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_sheet_id')->nullable()->after('standard_days');
            $table->unsignedTinyInteger('actual_working_days')->default(0)->after('attendance_sheet_id');
            $table->unsignedTinyInteger('paid_leave_days')->default(0)->after('actual_working_days');
            $table->unsignedTinyInteger('unpaid_leave_days')->default(0)->after('paid_leave_days');
            $table->unsignedTinyInteger('overtime_days')->default(0)->after('unpaid_leave_days');
            $table->string('attendance_note', 255)->nullable()->after('overtime_days');

            $table->foreign('attendance_sheet_id')
                ->references('id')->on('attendance_sheets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropForeign(['attendance_sheet_id']);
            $table->dropColumn([
                'attendance_sheet_id',
                'actual_working_days',
                'paid_leave_days',
                'unpaid_leave_days',
                'overtime_days',
                'attendance_note',
            ]);
        });
    }
};
