<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->foreignId('purchase_contract_id')
                ->nullable()
                ->after('source_id')
                ->constrained('purchase_contracts')
                ->nullOnDelete();

            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('purchase_contract_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();

            $table->foreignId('payment_schedule_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('purchase_contract_payment_schedules')
                ->nullOnDelete();

            $table->index('purchase_contract_id');
            $table->index('purchase_order_id');
            $table->index('payment_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->dropForeign(['purchase_contract_id']);
            $table->dropForeign(['purchase_order_id']);
            $table->dropForeign(['payment_schedule_id']);
            
            $table->dropColumn([
                'purchase_contract_id',
                'purchase_order_id',
                'payment_schedule_id'
            ]);
        });
    }
};
