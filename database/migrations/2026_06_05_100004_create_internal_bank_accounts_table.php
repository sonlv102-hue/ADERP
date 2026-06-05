<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('account_number', 50);
            $table->string('bank_name', 100)->nullable();
            $table->string('owner_name', 200)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_bank_accounts');
    }
};
