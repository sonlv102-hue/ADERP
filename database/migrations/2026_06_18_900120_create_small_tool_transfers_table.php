<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tool_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->unsignedBigInteger('small_tool_id');
            $table->foreign('small_tool_id')->references('id')->on('small_tools')->restrictOnDelete();
            $table->date('transfer_date');

            $table->string('from_department')->nullable();
            $table->string('to_department')->nullable();

            $table->unsignedBigInteger('from_employee_id')->nullable();
            $table->foreign('from_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->unsignedBigInteger('to_employee_id')->nullable();
            $table->foreign('to_employee_id')->references('id')->on('employees')->nullOnDelete();

            $table->unsignedBigInteger('from_project_id')->nullable();
            $table->foreign('from_project_id')->references('id')->on('projects')->nullOnDelete();
            $table->unsignedBigInteger('to_project_id')->nullable();
            $table->foreign('to_project_id')->references('id')->on('projects')->nullOnDelete();

            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();

            // Nếu TK chi phí tương lai thay đổi thì cập nhật lịch phân bổ pending
            $table->string('new_expense_account_code', 20)->nullable();
            $table->boolean('affects_future_allocation')->default(false);

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tool_transfers');
    }
};
