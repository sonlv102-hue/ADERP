<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->foreignId('supplier_prepayment_id')
                ->nullable()
                ->constrained('supplier_opening_advances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropForeign(['supplier_prepayment_id']);
            $table->dropColumn('supplier_prepayment_id');
        });
    }
};
