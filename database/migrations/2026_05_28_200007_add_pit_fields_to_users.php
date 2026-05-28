<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('dependents_count')->default(0)->after('allowance');
            $table->string('pit_tax_code', 20)->nullable()->after('dependents_count');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dependents_count', 'pit_tax_code']);
        });
    }
};
