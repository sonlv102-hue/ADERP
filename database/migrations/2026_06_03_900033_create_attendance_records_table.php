<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_sheet_id')->constrained('attendance_sheets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            // JSON {"1":"x","2":"P","7":"L",...} — key = ngày, value = ký hiệu
            $table->jsonb('days')->default('{}');
            // Tổng hợp (tự tính từ days)
            $table->unsignedTinyInteger('cong')->default(0);              // x + CT
            $table->unsignedTinyInteger('nghi_huong_luong')->default(0);  // P + Ô + NB + TS + L
            $table->unsignedTinyInteger('nghi_khong_luong')->default(0);  // KP
            $table->unsignedTinyInteger('ot')->default(0);                // OT
            $table->unsignedTinyInteger('tong')->default(0);              // tổng ngày có ký hiệu
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['attendance_sheet_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
