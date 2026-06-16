<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_advance_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opening_advance_id')->constrained('supplier_opening_advances')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->date('allocation_date');
            $table->decimal('allocated_amount', 18, 2);
            // active | reversed
            $table->string('status', 20)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reversed_by')->nullable()->constrained('users');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['opening_advance_id', 'status']);
            $table->index(['purchase_invoice_id', 'status']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_allocations');
    }
};
