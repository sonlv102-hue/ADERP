<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->string('business_type', 50)->nullable()->after('description');
            $table->string('journal_mode', 10)->default('auto')->after('business_type');
            $table->boolean('edited_by_user')->default(false)->after('journal_mode');
            $table->text('edit_reason')->nullable()->after('edited_by_user');
        });

        Schema::create('cash_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_voucher_id')->constrained()->cascadeOnDelete();
            $table->string('debit_account', 20);
            $table->string('credit_account', 20);
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->string('partner_type', 20)->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_voucher_lines');
        Schema::table('cash_vouchers', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'journal_mode', 'edited_by_user', 'edit_reason']);
        });
    }
};
