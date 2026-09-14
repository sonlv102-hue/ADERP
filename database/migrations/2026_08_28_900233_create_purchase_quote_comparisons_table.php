<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_quote_comparisons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();          // SSBG-2026-00001
            $table->string('name', 255);
            $table->date('comparison_date');
            $table->string('department', 150)->nullable(); // free string — no departments master
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft | quoted | completed
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quote_comparisons');
    }
};
