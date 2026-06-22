<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->string('contractor_name', 255)->nullable()->after('net_payment_amount');
            $table->string('contractor_representative', 100)->nullable()->after('contractor_name');
            $table->string('contractor_phone', 50)->nullable()->after('contractor_representative');
            $table->string('contractor_id_number', 50)->nullable()->after('contractor_phone');
            $table->string('contract_number', 100)->nullable()->after('contractor_id_number');
            $table->boolean('has_vat_invoice')->default(false)->after('contract_number');
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropColumn([
                'contractor_name', 'contractor_representative', 'contractor_phone',
                'contractor_id_number', 'contract_number', 'has_vat_invoice',
            ]);
        });
    }
};
