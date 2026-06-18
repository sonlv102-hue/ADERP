<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tools', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('small_tool_categories')->nullOnDelete();
            $table->string('unit', 30)->default('cái');
            $table->integer('quantity')->default(1);

            // Giá trị
            $table->decimal('original_cost', 15, 2)->default(0);   // excl VAT
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);       // incl VAT

            // Luồng nghiệp vụ
            $table->string('acquisition_type', 20)->default('stock'); // stock | direct
            $table->string('recognition_method', 20)->default('immediate'); // immediate | allocation
            $table->integer('allocation_periods')->nullable();
            $table->date('allocation_start_date')->nullable();

            // Ngày tháng
            $table->date('purchase_date')->nullable();
            $table->date('in_service_date')->nullable();

            // Bộ phận / người sử dụng
            $table->string('department')->nullable();
            $table->unsignedBigInteger('responsible_employee_id')->nullable();
            $table->foreign('responsible_employee_id')->references('id')->on('employees')->nullOnDelete();

            // Liên kết kho / dự án
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();

            // Liên kết mua hàng
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->unsignedBigInteger('purchase_invoice_id')->nullable();
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->nullOnDelete();

            // Thanh toán
            $table->string('payment_type', 20)->default('payable'); // payable | cash | bank
            $table->unsignedBigInteger('fund_id')->nullable();
            $table->foreign('fund_id')->references('id')->on('funds')->nullOnDelete();

            // Tài khoản kế toán
            $table->string('stock_account_code', 20)->default('1531');
            $table->string('pending_account_code', 20)->default('2422');
            $table->string('expense_account_code', 20)->default('6422');
            $table->string('payable_account_code', 20)->default('3311');

            // Phân bổ lũy kế
            $table->integer('periods_allocated')->default(0);
            $table->decimal('total_allocated', 15, 2)->default(0);

            // Liên kết bút toán
            $table->unsignedBigInteger('acquisition_journal_entry_id')->nullable();
            $table->foreign('acquisition_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->unsignedBigInteger('issue_journal_entry_id')->nullable();
            $table->foreign('issue_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            $table->string('status', 30)->default('draft'); // draft|in_stock|in_use|allocating|fully_allocated|broken|lost|disposed|cancelled
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tools');
    }
};
