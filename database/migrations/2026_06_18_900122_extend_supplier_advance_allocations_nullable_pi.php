<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing FK so we can alter the column
        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->dropForeign(['purchase_invoice_id']);
        });

        // Make purchase_invoice_id nullable — driver-specific syntax
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_advance_allocations ALTER COLUMN purchase_invoice_id DROP NOT NULL');
        } else {
            Schema::table('supplier_advance_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_invoice_id')->nullable()->change();
            });
        }

        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->foreign('purchase_invoice_id')
                ->references('id')->on('purchase_invoices')
                ->nullOnDelete();

            $table->foreignId('ar_ap_opening_balance_id')
                ->nullable()
                ->after('purchase_invoice_id')
                ->constrained('ar_ap_opening_balances')
                ->nullOnDelete();

            $table->index(['ar_ap_opening_balance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->dropForeign(['ar_ap_opening_balance_id']);
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropIndex(['ar_ap_opening_balance_id', 'status']);
            $table->dropColumn('ar_ap_opening_balance_id');
        });

        // Remove opening-balance-only allocations before restoring NOT NULL
        DB::statement('DELETE FROM supplier_advance_allocations WHERE purchase_invoice_id IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_advance_allocations ALTER COLUMN purchase_invoice_id SET NOT NULL');
        } else {
            Schema::table('supplier_advance_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('purchase_invoice_id')->nullable(false)->change();
            });
        }

        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->foreign('purchase_invoice_id')
                ->references('id')->on('purchase_invoices')
                ->cascadeOnDelete();
        });
    }
};
