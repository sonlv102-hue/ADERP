<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->boolean('edited_by_user')->default(false)->after('is_auto');
            $table->text('edit_reason')->nullable()->after('edited_by_user');
            $table->jsonb('original_lines')->nullable()->after('edit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['edited_by_user', 'edit_reason', 'original_lines']);
        });
    }
};
