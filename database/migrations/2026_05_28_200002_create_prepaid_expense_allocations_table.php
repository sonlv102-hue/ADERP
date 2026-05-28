<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepaid_expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prepaid_expense_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);          // YYYY-MM
            $table->decimal('amount', 15, 0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['prepaid_expense_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepaid_expense_allocations');
    }
};
