<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_counterparty_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('bank_account_number')->unique();
            $table->string('bank_name')->nullable();
            $table->string('party_type')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('party_name')->nullable();
            $table->foreignId('cash_flow_category_id')->nullable()->constrained('cash_flow_categories')->nullOnDelete();
            $table->unsignedTinyInteger('confidence')->default(90);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_counterparty_mappings');
    }
};
