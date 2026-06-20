<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_wip_correction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wip_entry_id')->constrained('project_wip_entries')->cascadeOnDelete();
            // cancel | transfer | reclass
            $table->string('action_type');
            $table->unsignedBigInteger('from_project_id')->nullable();
            $table->unsignedBigInteger('to_project_id')->nullable();
            $table->string('from_account')->nullable();
            $table->string('to_account')->nullable();
            $table->bigInteger('amount');
            $table->text('reason');
            $table->unsignedBigInteger('performed_by');
            // Bút toán đảo/điều chỉnh được tạo ra
            $table->foreignId('correction_je_id')->nullable()
                  ->constrained('journal_entries')->nullOnDelete();
            // WIP entry mới được tạo ra (cho transfer)
            $table->foreignId('new_wip_entry_id')->nullable()
                  ->constrained('project_wip_entries')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['wip_entry_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wip_correction_logs');
    }
};
