<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('small_tools', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('status');
            $table->string('opening_balance_period', 7)->nullable()->after('is_opening_balance');
            $table->string('opening_balance_note', 500)->nullable()->after('opening_balance_period');
        });
    }

    public function down(): void
    {
        Schema::table('small_tools', function (Blueprint $table) {
            $table->dropColumn(['is_opening_balance', 'opening_balance_period', 'opening_balance_note']);
        });
    }
};
