<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('partner_type', 20)->nullable()->after('supplier_id');
            $table->foreignId('customer_id')->nullable()->after('partner_type')
                  ->constrained('customers')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->after('customer_id')
                  ->constrained('employees')->nullOnDelete();
        });

        // Backfill: phiếu đã có supplier_id → partner_type = 'supplier'
        DB::statement("UPDATE cash_vouchers SET partner_type = 'supplier' WHERE supplier_id IS NOT NULL AND partner_type IS NULL");
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['employee_id']);
            $table->dropColumn(['partner_type', 'customer_id', 'employee_id']);
        });
    }
};
