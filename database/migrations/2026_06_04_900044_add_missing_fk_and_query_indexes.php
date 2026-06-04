<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL không tự tạo index cho FK columns — migration này bổ sung tất cả.
 * Ưu tiên: child-table FK (eager loading), composite (report/filter patterns).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Child-table FK indexes ────────────────────────────────────────────
        // Mỗi lần Eloquent ->with('items') đều scan toàn bảng nếu thiếu index này.

        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->index('stock_entry_id', 'sei_entry_id');
            $table->index('product_id',     'sei_product_id');
        });

        Schema::table('stock_exit_items', function (Blueprint $table) {
            $table->index('stock_exit_id', 'sexi_exit_id');
            $table->index('product_id',    'sexi_product_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id',   'oi_order_id');
            $table->index('product_id', 'oi_product_id');
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->index('quotation_id', 'qi_quotation_id');
            $table->index('product_id',   'qi_product_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->index('purchase_order_id', 'poi_po_id');
            $table->index('product_id',        'poi_product_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('invoice_id', 'pay_invoice_id');
        });

        // ─── FK indexes trên bảng chính ────────────────────────────────────────

        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id',              'orders_customer_id');
            $table->index(['customer_id', 'status'],  'orders_customer_status');
        });

        Schema::table('stock_entries', function (Blueprint $table) {
            $table->index('warehouse_id', 'se_warehouse_id');
        });

        Schema::table('stock_exits', function (Blueprint $table) {
            $table->index('warehouse_id',         'sex_warehouse_id');
            $table->index('order_id',             'sex_order_id');
            $table->index(['order_id', 'status'], 'sex_order_status');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('customer_id',             'inv_customer_id');
            $table->index('order_id',                'inv_order_id');
            $table->index(['customer_id', 'status'], 'inv_customer_status');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('supplier_id',              'po_supplier_id');
            $table->index(['supplier_id', 'status'],  'po_supplier_status');
        });

        Schema::table('product_serials', function (Blueprint $table) {
            $table->index('product_id', 'ps_product_id');
        });

        // ─── Composite indexes cho báo cáo kế toán ─────────────────────────────
        // journal_entries: entry_date đã có đơn lẻ; thêm composite (status, entry_date)
        // cho query: WHERE status='posted' ORDER BY entry_date DESC

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['status', 'entry_date'], 'je_status_date');
        });

        // journal_entry_lines: account_code đã có; thêm (account_code, debit/credit)
        // cho getAccountBalance() SUM(debit/credit) WHERE account_code=x
        // Dùng partial covering: index account_code đã đủ, thêm journal_entry_id độc lập
        // (composite je_id+ac đã tồn tại nhưng leading column là je_id, không giúp cho account scan)
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->index(['account_code', 'journal_entry_id'], 'jel_account_entry');
        });

        // ─── Một số bảng phụ hay bị bỏ qua ────────────────────────────────────

        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->index('project_id', 'wip_project_id');
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->index('bank_account_id', 'bt_account_id');
            $table->index(['bank_account_id', 'status'], 'bt_account_status');
        });

        Schema::table('prepaid_expenses', function (Blueprint $table) {
            $table->index('status', 'pe_status');
        });

        Schema::table('fixed_asset_depreciations', function (Blueprint $table) {
            $table->index('fixed_asset_id',          'fad_asset_id');
            $table->index(['fixed_asset_id', 'period'], 'fad_asset_period');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entry_items',    fn ($t) => [$t->dropIndex('sei_entry_id'),    $t->dropIndex('sei_product_id')]);
        Schema::table('stock_exit_items',     fn ($t) => [$t->dropIndex('sexi_exit_id'),    $t->dropIndex('sexi_product_id')]);
        Schema::table('order_items',          fn ($t) => [$t->dropIndex('oi_order_id'),     $t->dropIndex('oi_product_id')]);
        Schema::table('quotation_items',      fn ($t) => [$t->dropIndex('qi_quotation_id'), $t->dropIndex('qi_product_id')]);
        Schema::table('purchase_order_items', fn ($t) => [$t->dropIndex('poi_po_id'),       $t->dropIndex('poi_product_id')]);
        Schema::table('payments',             fn ($t) => $t->dropIndex('pay_invoice_id'));

        Schema::table('orders',          fn ($t) => [$t->dropIndex('orders_customer_id'), $t->dropIndex('orders_customer_status')]);
        Schema::table('stock_entries',   fn ($t) => $t->dropIndex('se_warehouse_id'));
        Schema::table('stock_exits',     fn ($t) => [$t->dropIndex('sex_warehouse_id'), $t->dropIndex('sex_order_id'), $t->dropIndex('sex_order_status')]);
        Schema::table('invoices',        fn ($t) => [$t->dropIndex('inv_customer_id'),  $t->dropIndex('inv_order_id'), $t->dropIndex('inv_customer_status')]);
        Schema::table('purchase_orders', fn ($t) => [$t->dropIndex('po_supplier_id'),   $t->dropIndex('po_supplier_status')]);
        Schema::table('product_serials', fn ($t) => $t->dropIndex('ps_product_id'));

        Schema::table('journal_entries',     fn ($t) => $t->dropIndex('je_status_date'));
        Schema::table('journal_entry_lines', fn ($t) => $t->dropIndex('jel_account_entry'));

        Schema::table('project_wip_entries',       fn ($t) => $t->dropIndex('wip_project_id'));
        Schema::table('bank_transactions',         fn ($t) => [$t->dropIndex('bt_account_id'), $t->dropIndex('bt_account_status')]);
        Schema::table('prepaid_expenses',          fn ($t) => $t->dropIndex('pe_status'));
        Schema::table('fixed_asset_depreciations', fn ($t) => [$t->dropIndex('fad_asset_id'), $t->dropIndex('fad_asset_period')]);
    }
};
