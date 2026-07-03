<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepaid_expenses', function (Blueprint $table) {
            // active|paused|completed|not_started
            $table->string('allocation_status', 20)->default('active')->after('status');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedBigInteger('paused_by')->nullable();
            $table->foreign('paused_by')->references('id')->on('users')->nullOnDelete();
            $table->string('pause_effective_period', 7)->nullable();
            $table->string('pause_reason', 500)->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->unsignedBigInteger('resumed_by')->nullable();
            $table->foreign('resumed_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('prepaid_expenses')->where('status', 'fully_amortized')->update(['allocation_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('prepaid_expenses', function (Blueprint $table) {
            $table->dropForeign(['paused_by']);
            $table->dropForeign(['resumed_by']);
            $table->dropColumn([
                'allocation_status', 'paused_at', 'paused_by',
                'pause_effective_period', 'pause_reason', 'resumed_at', 'resumed_by',
            ]);
        });
    }
};
