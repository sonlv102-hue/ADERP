<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->foreignId('subcontract_id')->nullable()->after('project_id')
                ->constrained('project_subcontracts')->nullOnDelete();
            $table->foreignId('subcontract_acceptance_id')->nullable()->after('subcontract_id')
                ->constrained('project_subcontract_acceptances')->nullOnDelete();
            $table->string('cost_group', 20)->nullable()->after('subcontract_acceptance_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['subcontract_id', 'subcontract_acceptance_id', 'cost_group']);
        });
    }
};
