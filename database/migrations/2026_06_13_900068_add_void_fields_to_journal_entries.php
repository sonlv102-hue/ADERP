<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: mở rộng CHECK constraint để cho phép 'voided'
        // SQLite: không có named CHECK constraint nên bỏ qua
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_status_check");
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('draft','posted','reversed','voided'))");
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('posted_at');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete()->after('voided_at');
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_status_check");
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('draft','posted','reversed'))");
        }
    }
};
