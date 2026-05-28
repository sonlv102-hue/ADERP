<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('payment_term_id')->nullable()->after('email')->constrained('payment_terms')->nullOnDelete();
            $table->decimal('credit_limit', 15, 0)->nullable()->after('payment_term_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('payment_term_id')->nullable()->after('email')->constrained('payment_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['payment_term_id']);
            $table->dropColumn(['payment_term_id', 'credit_limit']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropForeign(['payment_term_id']);
            $table->dropColumn('payment_term_id');
        });
    }
};
