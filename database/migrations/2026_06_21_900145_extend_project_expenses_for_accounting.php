<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                  ->nullable()->after('expense_date')
                  ->constrained('suppliers')->nullOnDelete();

            $table->unsignedBigInteger('purchase_invoice_id')
                  ->nullable()->after('supplier_id');

            $table->string('invoice_number', 100)->nullable()->after('purchase_invoice_id');

            $table->string('payment_method', 20)->default('payable')->after('invoice_number');

            $table->decimal('vat_rate', 5, 2)->nullable()->after('payment_method');

            $table->integer('vat_amount')->default(0)->after('vat_rate');

            $table->string('debit_account', 20)->nullable()->after('vat_amount');

            $table->string('credit_account', 20)->nullable()->after('debit_account');

            // FK tuỳ chọn — purchase_invoices có thể chưa có nhưng tạo ref cho sau này
            $table->index('supplier_id');
            $table->index('purchase_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropIndex(['supplier_id']);
            $table->dropIndex(['purchase_invoice_id']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn([
                'purchase_invoice_id', 'invoice_number', 'payment_method',
                'vat_rate', 'vat_amount', 'debit_account', 'credit_account',
            ]);
        });
    }
};
