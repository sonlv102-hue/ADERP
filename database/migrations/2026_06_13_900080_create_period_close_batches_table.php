<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_close_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();                        // KC-202606-001
            $table->foreignId('accounting_period_id')->constrained('accounting_periods');
            $table->string('fiscal_period', 7);                          // YYYY-MM
            $table->enum('status', ['draft', 'posted', 'reversed', 'voided'])->default('draft');
            $table->bigInteger('total_revenue')->default(0);             // VND, integer (rounded)
            $table->bigInteger('total_expense')->default(0);
            $table->bigInteger('profit_or_loss')->default(0);            // dương=lãi, âm=lỗ
            $table->unsignedInteger('journal_entry_count')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_close_batches');
    }
};
