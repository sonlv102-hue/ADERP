<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('type', 10);           // receipt | payment
            $table->string('status', 20)->default('draft'); // draft | confirmed | cancelled
            $table->foreignId('fund_id')->constrained('funds')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->date('voucher_date');
            $table->string('counterparty')->nullable(); // tên đối tác
            $table->string('description');
            $table->string('reference_type')->nullable(); // App\Models\Invoice ...
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fund_id', 'status']);
            $table->index(['voucher_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_vouchers');
    }
};
