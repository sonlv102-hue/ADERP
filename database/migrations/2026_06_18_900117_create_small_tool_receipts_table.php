<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tool_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->date('receipt_date');

            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();

            $table->unsignedBigInteger('purchase_invoice_id')->nullable();
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->nullOnDelete();

            $table->unsignedBigInteger('warehouse_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();

            $table->string('payment_type', 20)->default('payable'); // payable|cash|bank
            $table->unsignedBigInteger('fund_id')->nullable();
            $table->foreign('fund_id')->references('id')->on('funds')->nullOnDelete();

            $table->decimal('total_cost', 15, 2)->default(0);   // excl VAT
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0); // incl VAT

            $table->string('status', 20)->default('draft'); // draft|confirmed|cancelled
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('small_tool_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('small_tool_receipt_id');
            $table->foreign('small_tool_receipt_id')->references('id')->on('small_tool_receipts')->cascadeOnDelete();
            $table->unsignedBigInteger('small_tool_id');
            $table->foreign('small_tool_id')->references('id')->on('small_tools')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_price', 15, 2)->default(0); // per unit, excl VAT
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tool_receipt_items');
        Schema::dropIfExists('small_tool_receipts');
    }
};
