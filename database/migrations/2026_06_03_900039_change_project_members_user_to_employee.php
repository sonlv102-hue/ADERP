<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'user_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->foreignId('employee_id')->after('project_id')
                ->constrained('employees')->cascadeOnDelete();
            $table->unique(['project_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'employee_id']);
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['project_id', 'user_id']);
        });
    }
};
