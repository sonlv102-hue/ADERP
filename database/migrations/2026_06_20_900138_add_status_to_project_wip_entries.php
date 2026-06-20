<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            // active | cancelled | adjusted | transferred | reversed
            $table->string('status')->default('active')->after('stock_exit_item_id');
            $table->string('cancel_reason')->nullable()->after('status');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancel_reason');
            $table->timestampTz('cancelled_at')->nullable()->after('cancelled_by');
            // FK về entry gốc nếu entry này là kết quả điều chỉnh/chuyển
            $table->foreignId('correction_of_id')->nullable()->after('cancelled_at')
                  ->constrained('project_wip_entries')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('project_wip_entries', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('correction_of_id');
            $table->dropColumn(['status', 'cancel_reason', 'cancelled_by', 'cancelled_at']);
        });
    }
};
