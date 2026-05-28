<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();       // BT-20260001
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('reference_type', 50)->nullable(); // invoice, payment, stock_entry, ...
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft')->index();
            $table->boolean('is_auto')->default(false); // true = tự động hạch toán từ chứng từ
            $table->unsignedBigInteger('reversed_by_id')->nullable(); // bút toán đảo
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->index(['entry_date']);
            $table->index(['reference_type', 'reference_id']);
            $table->foreign('reversed_by_id')->references('id')->on('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
