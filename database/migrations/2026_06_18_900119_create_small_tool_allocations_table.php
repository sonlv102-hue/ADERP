<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_tool_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('small_tool_id');
            $table->foreign('small_tool_id')->references('id')->on('small_tools')->cascadeOnDelete();
            $table->string('period', 7);  // YYYY-MM
            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('amount', 15, 2);           // phân bổ kỳ này
            $table->decimal('accumulated_before', 15, 2)->default(0); // lũy kế trước kỳ
            $table->decimal('remaining_after', 15, 2)->default(0);   // còn lại sau kỳ

            $table->string('debit_account', 20)->default('6422');  // 6422/6421/154
            $table->string('credit_account', 20)->default('2422');

            $table->string('status', 20)->default('pending'); // pending|posted|reversed|cancelled
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['small_tool_id', 'period']);
            $table->index('period');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_tool_allocations');
    }
};
