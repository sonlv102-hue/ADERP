<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('days')->default(0);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default terms
        DB::table('payment_terms')->insert([
            ['code' => 'COD',   'name' => 'Thanh toán ngay (COD)',    'days' => 0,  'description' => 'Cash on delivery', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NET15', 'name' => 'Net 15 ngày',               'days' => 15, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NET30', 'name' => 'Net 30 ngày',               'days' => 30, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NET45', 'name' => 'Net 45 ngày',               'days' => 45, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NET60', 'name' => 'Net 60 ngày',               'days' => 60, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'NET90', 'name' => 'Net 90 ngày',               'days' => 90, 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
