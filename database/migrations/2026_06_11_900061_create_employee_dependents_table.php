<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_dependents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('dependent_name', 200);
            $table->string('relationship', 30)
                  ->comment('spouse|child|parent|sibling|other');
            $table->string('tax_id', 20)->nullable()->comment('Mã số thuế người phụ thuộc nếu có');

            // Kỳ hiệu lực giảm trừ
            $table->date('start_date')->comment('Ngày bắt đầu tính giảm trừ');
            $table->date('end_date')->nullable()->comment('Ngày kết thúc, null = vẫn đang tính');

            // Hồ sơ và trạng thái
            $table->text('documentation_notes')->nullable()->comment('Ghi chú hồ sơ chứng minh');
            $table->string('registration_status', 20)->default('approved')
                  ->comment('pending|approved|rejected');

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('employee_id');
            $table->index(['employee_id', 'start_date', 'end_date'], 'dep_employee_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_dependents');
    }
};
