<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pit_configs', function (Blueprint $table) {
            $table->id();

            // Kỳ hiệu lực — nullable effective_to nghĩa là "hiện tại đang áp dụng"
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Mức giảm trừ (VND, nguyên)
            $table->decimal('personal_deduction', 18, 0)->comment('Giảm trừ bản thân VND/tháng');
            $table->decimal('dependent_deduction', 18, 0)->comment('Giảm trừ người phụ thuộc VND/người/tháng');
            $table->decimal('insurance_cap', 18, 0)->comment('Trần đóng BHXH VND/tháng');

            // Biểu thuế lũy tiến — JSON array of [{cap, rate}]
            // null = dùng biểu cố định 7 bậc trong code
            $table->json('brackets')->nullable()->comment('Biểu thuế lũy tiến JSON, null = dùng mặc định 7 bậc');

            $table->string('legal_basis', 300)->nullable()->comment('Căn cứ pháp lý (tên nghị định/quyết định)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['effective_from', 'effective_to', 'is_active'], 'pit_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pit_configs');
    }
};
