<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name')->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
        });

        // Seed initial departments from employees table
        $existingDepts = DB::table('employees')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->distinct()
            ->pluck('department');

        $adminUser = DB::table('users')->first();
        $adminId = $adminUser ? $adminUser->id : 1;

        $index = 1;
        foreach ($existingDepts as $deptName) {
            DB::table('departments')->insert([
                'code' => 'BP-' . str_pad($index++, 4, '0', STR_PAD_LEFT),
                'name' => $deptName,
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
