<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customs_status')->default('not_required')->after('notes');
            $table->timestamp('customs_declared_at')->nullable()->after('customs_status');
            $table->string('customs_document_path')->nullable()->after('customs_declared_at');
            $table->string('customs_document_name')->nullable()->after('customs_document_path');
            $table->text('customs_notes')->nullable()->after('customs_document_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customs_status', 'customs_declared_at', 'customs_document_path', 'customs_document_name', 'customs_notes']);
        });
    }
};
