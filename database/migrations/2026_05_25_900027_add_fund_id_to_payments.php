<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('fund_id')->nullable()->after('payment_method')
                ->constrained('funds')->nullOnDelete();
        });

        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            $table->foreignId('fund_id')->nullable()->after('payment_method')
                ->constrained('funds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Fund::class, 'fund_id');
            $table->dropColumn('fund_id');
        });

        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Fund::class, 'fund_id');
            $table->dropColumn('fund_id');
        });
    }
};
