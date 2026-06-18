<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tool_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->unsignedBigInteger('small_tool_id');
            $table->foreign('small_tool_id')->references('id')->on('small_tools')->restrictOnDelete();

            $table->string('disposal_type', 20); // broken|lost|liquidated
            $table->date('disposal_date');
            $table->text('reason');

            // Giá trị tại thời điểm xử lý
            $table->decimal('net_value_snapshot', 15, 2)->default(0); // giá trị còn lại
            $table->string('expense_account_code', 20)->default('6422'); // TK ghi nhận tổn thất

            // Thanh lý có thu hồi
            $table->decimal('recovery_amount', 15, 2)->default(0);
            $table->string('recovery_account_code', 20)->nullable(); // 711|5118
            $table->decimal('recovery_vat_amount', 15, 2)->default(0);
            $table->decimal('disposal_cost', 15, 2)->default(0);

            $table->string('status', 20)->default('draft'); // draft|approved|cancelled
            $table->json('journal_entry_ids')->nullable(); // array of JE ids

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tool_disposals');
    }
};
