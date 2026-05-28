<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepaid_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('description', 300);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_code', 10)->default('242');   // TK 142 (ngắn hạn) hoặc 242 (dài hạn)
            $table->string('expense_account', 10)->default('642'); // TK phân bổ vào chi phí
            $table->decimal('total_amount', 15, 0);               // VND, không có xu
            $table->date('start_date');
            $table->integer('months');                             // Số tháng phân bổ
            $table->decimal('monthly_amount', 15, 0);             // total_amount / months (làm tròn)
            $table->decimal('amortized_amount', 15, 0)->default(0);
            $table->string('status', 20)->default('active');       // active / fully_amortized / cancelled
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepaid_expenses');
    }
};
