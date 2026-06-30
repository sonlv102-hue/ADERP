<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            $table->string('delete_reason', 500)->nullable()->after('deleted_by');

            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_opening_advances', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn(['deleted_at', 'deleted_by', 'delete_reason']);
        });
    }
};
