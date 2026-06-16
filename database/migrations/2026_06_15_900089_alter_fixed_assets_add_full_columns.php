<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change status from enum/varchar to varchar(30) — PostgreSQL only (SQLite tests skip)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fixed_assets ALTER COLUMN status TYPE VARCHAR(30) USING status::VARCHAR');
            DB::statement("ALTER TABLE fixed_assets ALTER COLUMN status SET DEFAULT 'pending_use'");
        }

        Schema::table('fixed_assets', function (Blueprint $table) {
            // Category FK
            $table->unsignedBigInteger('category_id')->nullable()->after('category');
            $table->foreign('category_id')->references('id')->on('fixed_asset_categories')->nullOnDelete();

            // Asset classification
            $table->string('asset_type', 30)->default('tangible')->after('category_id'); // tangible/intangible/finance_lease
            $table->string('serial_number')->nullable()->after('asset_type');
            $table->string('source_type', 30)->nullable()->after('serial_number'); // purchased/self_built/contributed/transferred/imported/other

            // Supplier / invoice linkage
            $table->unsignedBigInteger('supplier_id')->nullable()->after('source_type');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->unsignedBigInteger('purchase_invoice_id')->nullable()->after('supplier_id');
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->nullOnDelete();
            $table->date('invoice_date')->nullable()->after('purchase_invoice_id');

            // Dates
            $table->date('recognition_date')->nullable()->after('acquisition_date');     // ngày ghi tăng
            $table->date('placed_in_service_date')->nullable()->after('recognition_date'); // ngày đưa vào sử dụng
            $table->date('depreciation_start_date')->nullable()->after('placed_in_service_date');
            $table->date('depreciation_end_date')->nullable()->after('depreciation_start_date');

            // Costs
            $table->decimal('vat_amount', 15, 2)->default(0)->after('acquisition_cost');
            $table->decimal('total_amount', 15, 2)->default(0)->after('vat_amount');         // nguyên giá + VAT
            $table->decimal('depreciable_amount', 15, 2)->default(0)->after('total_amount'); // giá trị tính khấu hao
            $table->decimal('opening_accumulated_depreciation', 15, 2)->default(0)->after('depreciable_amount');

            // Department (new column — original table only has 'location')
            $table->string('department')->nullable()->after('location');

            // Account codes (store as varchar codes, validated at posting)
            $table->string('original_cost_account_code', 20)->nullable()->default('2111');
            $table->string('accumulated_dep_account_code', 20)->nullable()->default('2141');
            $table->string('depreciation_expense_account_code', 20)->nullable()->default('6421');
            $table->string('payable_account_code', 20)->nullable()->default('3311');

            // Acquisition JE
            $table->unsignedBigInteger('acquisition_journal_entry_id')->nullable();
            $table->foreign('acquisition_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            // Audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Backfill depreciable_amount = acquisition_cost where 0
        DB::statement('UPDATE fixed_assets SET depreciable_amount = acquisition_cost WHERE depreciable_amount = 0');
        DB::statement("UPDATE fixed_assets SET status = 'active' WHERE status = 'active'");
        DB::statement("UPDATE fixed_assets SET status = 'fully_depreciated' WHERE status = 'fully_depreciated'");
        DB::statement("UPDATE fixed_assets SET status = 'disposed' WHERE status = 'disposed'");
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropForeign(['acquisition_journal_entry_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'category_id', 'asset_type', 'serial_number', 'source_type',
                'supplier_id', 'purchase_invoice_id', 'invoice_date',
                'recognition_date', 'placed_in_service_date',
                'depreciation_start_date', 'depreciation_end_date',
                'vat_amount', 'total_amount', 'depreciable_amount', 'opening_accumulated_depreciation',
                'original_cost_account_code', 'accumulated_dep_account_code',
                'depreciation_expense_account_code', 'payable_account_code',
                'acquisition_journal_entry_id', 'created_by', 'updated_by',
            ]);
        });

        DB::statement("ALTER TABLE fixed_assets ALTER COLUMN status TYPE VARCHAR(30)");
        DB::statement("ALTER TABLE fixed_assets ALTER COLUMN status SET DEFAULT 'active'");
    }
};
