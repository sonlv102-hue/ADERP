<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tool_issues', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->date('issue_date');

            $table->string('department')->nullable();
            $table->unsignedBigInteger('responsible_employee_id')->nullable();
            $table->foreign('responsible_employee_id')->references('id')->on('employees')->nullOnDelete();

            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();

            $table->string('recognition_method', 20)->default('immediate'); // immediate|allocation
            $table->integer('allocation_periods')->nullable();
            $table->date('allocation_start_date')->nullable();

            $table->string('expense_account_code', 20)->default('6422');
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->string('status', 20)->default('draft'); // draft|confirmed|cancelled
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('small_tool_issue_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('small_tool_issue_id');
            $table->foreign('small_tool_issue_id')->references('id')->on('small_tool_issues')->cascadeOnDelete();
            $table->unsignedBigInteger('small_tool_id');
            $table->foreign('small_tool_id')->references('id')->on('small_tools')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('amount', 15, 2)->default(0); // giá trị xuất dùng
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tool_issue_items');
        Schema::dropIfExists('small_tool_issues');
    }
};
