<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name')->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
        });

        // Seed initial positions from employees table
        $existingPositions = DB::table('employees')
            ->whereNotNull('position')
            ->where('position', '<>', '')
            ->distinct()
            ->pluck('position');

        $adminUser = DB::table('users')->first();
        $adminId = $adminUser ? $adminUser->id : 1;

        $index = 1;
        foreach ($existingPositions as $posName) {
            DB::table('positions')->insert([
                'code' => 'CV-' . str_pad($index++, 4, '0', STR_PAD_LEFT),
                'name' => $posName,
                'is_active' => true,
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
