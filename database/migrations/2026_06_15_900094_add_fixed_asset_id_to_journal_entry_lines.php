<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('fixed_asset_id')->nullable()->after('project_id');
            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['fixed_asset_id']);
            $table->dropColumn('fixed_asset_id');
        });
    }
};
