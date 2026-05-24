<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('type', 50);

            // Liên kết nghiệp vụ (tùy chọn)
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // Người nhận
            $table->string('recipient_name', 200);
            $table->text('recipient_info')->nullable();

            // Giá trị
            $table->decimal('amount', 18, 2);
            $table->decimal('rate', 8, 4)->nullable();
            $table->string('payment_method', 30)->default('bank_transfer');

            // Lịch
            $table->date('planned_date')->nullable();
            $table->date('paid_date')->nullable();

            // Trạng thái & workflow
            $table->string('status', 30)->default('draft');
            $table->text('reject_reason')->nullable();

            // Duyệt L1 (trưởng phòng)
            $table->foreignId('approver1_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved1_at')->nullable();

            // Duyệt L2 (giám đốc)
            $table->foreignId('approver2_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved2_at')->nullable();

            // Thanh toán (kế toán)
            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
