<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 10);
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 15, 0)->default(0);    // VND không có xu
            $table->decimal('credit', 15, 0)->default(0);
            $table->tinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('account_code')->references('code')->on('account_codes');
            $table->index('account_code');
            $table->index(['journal_entry_id', 'account_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
