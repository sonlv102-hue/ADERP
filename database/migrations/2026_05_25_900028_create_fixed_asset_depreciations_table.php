<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('amount', 18, 2);
            $table->decimal('accumulated_before', 18, 2)->default(0);
            $table->decimal('net_book_value_after', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
    }
};
