<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('movement_type', 40); // placed_in_service / department_transfer / account_change / suspended / resumed / revaluation / other
            $table->date('movement_date');
            $table->string('from_department')->nullable();
            $table->string('to_department')->nullable();
            $table->string('from_expense_account_code', 20)->nullable();
            $table->string('to_expense_account_code', 20)->nullable();
            $table->date('effective_from')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_movements');
    }
};
