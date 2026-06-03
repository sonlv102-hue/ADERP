<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            $table->string('item_usage_type')->default('commercial')->after('notes');
            $table->foreignId('project_id')->nullable()->constrained('projects')->after('item_usage_type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_exits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('item_usage_type');
        });
    }
};
