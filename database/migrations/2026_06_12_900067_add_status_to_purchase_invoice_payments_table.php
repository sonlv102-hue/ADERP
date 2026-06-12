<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            // active = bình thường | voided = đã thu hồi (JE đã đảo, record giữ lại cho audit)
            $table->string('status', 20)->default('active')->after('created_by');
            $table->text('void_reason')->nullable()->after('status');
            $table->foreignId('voided_by')->nullable()->after('void_reason')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['status', 'void_reason', 'voided_by', 'voided_at']);
        });
    }
};
