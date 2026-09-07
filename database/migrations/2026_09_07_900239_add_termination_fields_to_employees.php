<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('termination_date')->nullable()->after('contract_end_date');
            $table->string('termination_reason', 255)->nullable()->after('termination_date');
            $table->text('termination_note')->nullable()->after('termination_reason');
            $table->string('termination_decision_no', 100)->nullable()->after('termination_note');
            $table->date('termination_decision_date')->nullable()->after('termination_decision_no');
            $table->foreignId('terminated_by')->nullable()->after('termination_decision_date')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('terminated_at')->nullable()->after('terminated_by');

            $table->index('termination_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['termination_date']);
            $table->dropConstrainedForeignId('terminated_by');
            $table->dropColumn([
                'termination_date', 'termination_reason', 'termination_note',
                'termination_decision_no', 'termination_decision_date', 'terminated_at',
            ]);
        });
    }
};
