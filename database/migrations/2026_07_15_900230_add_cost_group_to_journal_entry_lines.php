<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('cost_group', 20)->nullable()->after('project_id');
            $table->string('project_cost_note', 255)->nullable()->after('cost_group');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropColumn(['cost_group', 'project_cost_note']);
        });
    }
};
