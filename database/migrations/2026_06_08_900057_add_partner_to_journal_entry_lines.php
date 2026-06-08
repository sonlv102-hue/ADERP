<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('partner_type', 20)->nullable()->after('project_id');
            $table->unsignedBigInteger('partner_id')->nullable()->after('partner_type');
            $table->index(['partner_type', 'partner_id'], 'jel_partner');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('jel_partner');
            $table->dropColumn(['partner_type', 'partner_id']);
        });
    }
};
