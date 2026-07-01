<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('national_id', 20)->nullable()->after('gender');
            $table->date('national_id_issue_date')->nullable()->after('national_id');
            $table->string('national_id_issue_place', 255)->nullable()->after('national_id_issue_date');
            $table->date('contract_start_date')->nullable()->after('employment_type');
            $table->date('contract_end_date')->nullable()->after('contract_start_date');
            $table->string('social_insurance_no', 20)->nullable()->after('pit_tax_code');
            $table->string('bank_account_no', 30)->nullable()->after('social_insurance_no');
            $table->string('bank_name', 100)->nullable()->after('bank_account_no');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'national_id', 'national_id_issue_date', 'national_id_issue_place',
                'contract_start_date', 'contract_end_date',
                'social_insurance_no', 'bank_account_no', 'bank_name',
            ]);
        });
    }
};
