<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('source_item_id')->nullable()->after('source_id');
            $table->decimal('vat_amount', 18, 2)->nullable()->after('amount');

            $table->index(['source_type', 'source_id', 'source_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id', 'source_item_id']);
            $table->dropColumn(['source_item_id', 'vat_amount']);
        });
    }
};
