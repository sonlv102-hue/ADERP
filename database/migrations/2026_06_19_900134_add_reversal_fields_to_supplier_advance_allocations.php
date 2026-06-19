<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_entry_id')->nullable()->after('journal_entry_id');
            $table->text('reverse_reason')->nullable()->after('reversed_at');

            $table->foreign('reversal_entry_id')
                  ->references('id')->on('journal_entries')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_advance_allocations', function (Blueprint $table) {
            $table->dropForeign(['reversal_entry_id']);
            $table->dropColumn(['reversal_entry_id', 'reverse_reason']);
        });
    }
};
