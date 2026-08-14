<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            // Đánh dấu BHXH/BHYT/BHTN (nhóm) hoặc PIT đã bị kế toán ghi đè thủ công —
            // auto-sync (Đồng bộ từ hồ sơ NV / cập nhật hồ sơ NV) phải giữ nguyên, không tính lại.
            $table->boolean('insurance_overridden')->default(false)->after('pit');
            $table->boolean('pit_overridden')->default(false)->after('insurance_overridden');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete()->after('pit_overridden');
            $table->timestampTz('overridden_at')->nullable()->after('overridden_by');
            $table->text('override_reason')->nullable()->after('overridden_at');

            // Chống trùng: mỗi nhân viên chỉ có 1 dòng lương trên 1 bảng lương
            // (NULL employee_id — dữ liệu cũ dùng user_id — không bị ràng buộc bởi unique index)
            $table->unique(['payroll_id', 'employee_id'], 'payroll_items_payroll_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropUnique('payroll_items_payroll_employee_unique');
            $table->dropForeign(['overridden_by']);
            $table->dropColumn(['insurance_overridden', 'pit_overridden', 'overridden_by', 'overridden_at', 'override_reason']);
        });
    }
};
