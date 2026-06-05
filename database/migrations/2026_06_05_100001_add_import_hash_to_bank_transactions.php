<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('import_hash', 64)->nullable()->after('import_batch');
            $table->unique(['bank_account_id', 'import_hash'], 'bank_tx_import_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropUnique('bank_tx_import_hash_unique');
            $table->dropColumn('import_hash');
        });
    }
};
