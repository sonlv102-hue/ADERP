<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_opening_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('opening_date');
            $table->string('account_code', 10)->default('3311');
            $table->decimal('amount', 18, 2);
            $table->decimal('remaining_amount', 18, 2);
            $table->string('currency', 3)->default('VND');
            $table->string('reference_no', 100)->nullable();
            $table->string('bank_transaction_ref', 100)->nullable();
            $table->date('original_payment_date')->nullable();
            $table->text('original_payment_note')->nullable();
            // open | partially_applied | fully_applied | cancelled
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('supplier_id');
            $table->index(['fiscal_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_opening_advances');
    }
};
