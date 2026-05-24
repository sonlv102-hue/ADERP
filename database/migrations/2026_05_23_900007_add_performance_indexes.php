<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // stock_movements — truy vấn nhiều nhất (inventory report, tồn kho, dashboard)
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['product_id', 'warehouse_id', 'created_at'], 'sm_product_warehouse_date');
            $table->index(['product_id', 'created_at'], 'sm_product_date');
        });

        // stock_entries — filter theo PO và status thường xuyên
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->index(['purchase_order_id', 'status'], 'se_po_status');
        });

        // product_serials — filter status + warehouse khi tạo phiếu xuất
        Schema::table('product_serials', function (Blueprint $table) {
            $table->index(['status', 'warehouse_id'], 'ps_status_warehouse');
        });

        // orders — filter theo status
        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'orders_status');
        });

        // invoices — filter theo status
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('status', 'invoices_status');
        });

        // purchase_orders — filter theo status
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('status', 'po_status');
        });

        // customers — filter lead_status cho dashboard/CRM
        Schema::table('customers', function (Blueprint $table) {
            $table->index('lead_status', 'customers_lead_status');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('sm_product_warehouse_date');
            $table->dropIndex('sm_product_date');
        });
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropIndex('se_po_status');
        });
        Schema::table('product_serials', function (Blueprint $table) {
            $table->dropIndex('ps_status_warehouse');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_status');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_lead_status');
        });
    }
};
