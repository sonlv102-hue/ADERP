<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // CC-202601
            $table->string('period', 7);                   // YYYY-MM
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();

            $table->unique('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sheets');
    }
};
