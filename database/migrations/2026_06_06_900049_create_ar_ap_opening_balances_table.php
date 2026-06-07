<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_ap_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('type', 2);              // 'ar' (phải thu) | 'ap' (phải trả)
            $table->string('period', 7);            // YYYY-MM
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('invoice_ref')->nullable();   // Số hóa đơn/chứng từ đối ứng
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 18, 2);           // Tổng giá trị
            $table->decimal('remaining_amount', 18, 2); // Còn phải thu/trả
            $table->text('note')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['type', 'period']);
            $table->index('customer_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_ap_opening_balances');
    }
};
