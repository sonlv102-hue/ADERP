<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->foreignId('shareholder_id')->nullable()->after('employee_id')
                ->constrained('shareholders')->nullOnDelete();
            $table->string('advance_purpose', 255)->nullable()->after('shareholder_id');
            $table->date('advance_due_date')->nullable()->after('advance_purpose');
            $table->foreignId('advance_reference_id')->nullable()->after('advance_due_date')
                ->constrained('cash_vouchers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropForeign(['shareholder_id']);
            $table->dropForeign(['advance_reference_id']);
            $table->dropColumn(['shareholder_id', 'advance_purpose', 'advance_due_date', 'advance_reference_id']);
        });
    }
};
