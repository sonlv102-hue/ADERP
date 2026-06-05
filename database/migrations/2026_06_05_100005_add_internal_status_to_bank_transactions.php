<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            // pending = chưa xử lý | docs_done = đã có hồ sơ đối ứng
            // needs_return = cần hoàn ứng | returned = đã hoàn ứng
            $table->string('internal_status', 20)->nullable()->after('alert_note');
            $table->text('internal_note')->nullable()->after('internal_status');
            $table->decimal('return_amount', 18, 0)->nullable()->after('internal_note');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn(['internal_status', 'internal_note', 'return_amount']);
        });
    }
};
