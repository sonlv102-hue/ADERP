<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_codes', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 200);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense', 'contra'])->index();
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->string('parent_code', 10)->nullable()->index();
            $table->tinyInteger('level')->default(1);
            $table->boolean('is_detail')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        // Self-referential FK phải thêm sau khi bảng đã tồn tại (PostgreSQL requirement)
        Schema::table('account_codes', function (Blueprint $table) {
            $table->foreign('parent_code')->references('code')->on('account_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_codes');
    }
};
