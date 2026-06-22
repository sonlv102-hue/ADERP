<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('cash_flow_code', 5)->nullable()->after('business_type')
                ->comment('B03-DNN direct method line code: 01-07, 21-25, 31-35');
            $table->index('cash_flow_code');
        });
    }

    public function down(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropIndex(['cash_flow_code']);
            $table->dropColumn('cash_flow_code');
        });
    }
};
