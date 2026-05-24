<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_contract_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_contract_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('percentage', 5, 2);
            $table->decimal('amount', 15, 2);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('paid_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_contract_payment_schedules');
    }
};
