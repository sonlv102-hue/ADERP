<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->string('advance_type', 20)->default('opening_balance')->after('supplier_id');
            $table->string('source_type', 20)->nullable()->after('advance_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index('advance_type');
        });

        // fiscal_year: make nullable — PostgreSQL only (SQLite doesn't support ALTER COLUMN)
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE supplier_opening_advances ALTER COLUMN fiscal_year DROP NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->dropIndex(['advance_type']);
            $table->dropColumn(['advance_type', 'source_type', 'source_id']);
        });

        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE supplier_opening_advances ALTER COLUMN fiscal_year SET NOT NULL'
            );
        }
    }
};
