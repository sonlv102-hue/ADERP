<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('remaining_amount');
        });

        Schema::create('supplier_advance_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_advance_id')->constrained('supplier_opening_advances');
            $table->foreignId('supplier_id')->constrained();
            $table->date('refund_date');
            $table->decimal('amount', 15, 2);
            $table->string('refund_method'); // cash | bank
            $table->foreignId('fund_id')->nullable()->constrained();
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('status')->default('confirmed'); // confirmed | cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_refunds');
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
