<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_opening_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('advance_type')->default('opening_balance'); // opening_balance | advance_receipt
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->integer('fiscal_year')->nullable();
            $table->date('advance_date');
            $table->string('account_code')->default('131UT'); // TK khách hàng ứng trước
            $table->decimal('amount', 15, 2);
            $table->decimal('remaining_amount', 15, 2);
            $table->string('currency', 3)->default('VND');
            $table->string('reference_no', 100)->nullable();
            $table->string('status')->default('open'); // open | partially_applied | fully_applied | cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('advance_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_opening_advances');
    }
};
