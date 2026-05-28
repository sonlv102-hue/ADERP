<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('e_inv_template', 30)->nullable()->after('notes');
            $table->string('e_inv_series', 30)->nullable()->after('e_inv_template');
            $table->unsignedInteger('e_inv_number')->nullable()->after('e_inv_series');
            $table->string('e_inv_status', 20)->nullable()->after('e_inv_number');
            $table->timestamp('e_inv_issued_at')->nullable()->after('e_inv_status');
            $table->text('e_inv_cancel_reason')->nullable()->after('e_inv_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'e_inv_template', 'e_inv_series', 'e_inv_number',
                'e_inv_status', 'e_inv_issued_at', 'e_inv_cancel_reason',
            ]);
        });
    }
};
