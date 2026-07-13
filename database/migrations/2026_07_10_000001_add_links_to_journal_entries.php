<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            $table->foreignId('purchase_contract_id')
                ->nullable()
                ->constrained('purchase_contracts')
                ->nullOnDelete();

            $table->foreignId('purchase_order_id')
                ->nullable()
                ->constrained('purchase_orders')
                ->nullOnDelete();

            $table->foreignId('supplier_prepayment_id')
                ->nullable()
                ->constrained('supplier_opening_advances')
                ->nullOnDelete();

            $table->index('supplier_id');
            $table->index('purchase_contract_id');
            $table->index('purchase_order_id');
            $table->index('supplier_prepayment_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['purchase_contract_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropForeign(['supplier_prepayment_id']);

            $table->dropColumn([
                'supplier_id',
                'purchase_contract_id',
                'purchase_order_id',
                'supplier_prepayment_id',
            ]);
        });
    }
};
