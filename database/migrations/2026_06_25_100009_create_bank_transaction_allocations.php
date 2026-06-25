<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transaction_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->string('party_type', 20)->nullable(); // customer|supplier
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('target_type', 30);           // invoice|purchase_invoice|other
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('account_code', 20);          // 131|3311|3312|331UT|131UT|etc
            $table->decimal('allocated_amount', 18, 0);
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->string('status', 15)->default('active'); // active|cancelled
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['bank_transaction_id', 'status']);
            $table->index(['target_type', 'target_id']);
            $table->index(['party_type', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transaction_allocations');
    }
};
