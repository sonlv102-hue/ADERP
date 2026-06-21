<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            // Trạng thái: draft → posted → cancelled
            $table->string('status', 20)->default('posted')->after('credit_account');

            // Liên kết bút toán và WIP entry do expense này tạo ra
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
            $table->unsignedBigInteger('project_wip_entry_id')->nullable()->after('journal_entry_id');

            // Trường phụ tuỳ TK Có
            $table->foreignId('employee_id')->nullable()->after('project_wip_entry_id')
                  ->constrained('employees')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->after('employee_id')
                  ->constrained('funds')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->after('fund_id')
                  ->constrained('bank_accounts')->nullOnDelete();

            $table->index('journal_entry_id');
            $table->index('project_wip_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
            $table->dropIndex(['project_wip_entry_id']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('fund_id');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn(['status', 'journal_entry_id', 'project_wip_entry_id']);
        });
    }
};
