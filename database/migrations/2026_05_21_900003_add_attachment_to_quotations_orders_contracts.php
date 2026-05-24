<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('notes');
            $table->string('file_name')->nullable()->after('file_path');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('notes');
            $table->string('file_name')->nullable()->after('file_path');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name']);
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('file_name');
        });
    }
};
