<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('disposal_type', 30); // liquidation / sale / damage / other
            $table->date('disposal_date');
            $table->decimal('original_cost_snapshot', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation_snapshot', 15, 2)->default(0);
            $table->decimal('net_book_value_snapshot', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('selling_vat_amount', 15, 2)->default(0);
            $table->decimal('disposal_cost', 15, 2)->default(0);
            $table->decimal('disposal_vat_amount', 15, 2)->default(0);
            $table->decimal('gain_loss', 15, 2)->default(0); // (computed: selling_price - disposal_cost - NBV)
            $table->string('buyer_name')->nullable();
            $table->string('disposal_account_code', 20)->nullable()->default('811');
            $table->string('income_account_code', 20)->nullable()->default('711');
            $table->string('status', 20)->default('draft'); // draft / posted / reversed
            // JSON array of journal_entry_ids (can be multiple: writeoff + revenue + cost)
            $table->json('journal_entry_ids')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
