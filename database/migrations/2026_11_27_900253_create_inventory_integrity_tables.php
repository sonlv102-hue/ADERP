<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo "Kiểm soát sai lệch tồn kho và kế toán": lưu kết quả audit, giao việc, lịch sử xử lý.
 * Chỉ bảng MỚI — không đụng bảng nghiệp vụ kho/kế toán.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_audit_runs', function (Blueprint $t) {
            $t->id();
            $t->string('scope_type', 20);                 // all|product|warehouse|project|document|issue
            $t->string('scope_value', 100)->nullable();
            $t->string('rules_version', 20);
            $t->string('status', 20)->default('running'); // running|success|failed
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->unsignedInteger('rows_checked')->default(0);
            $t->unsignedInteger('products_checked')->default(0);
            $t->unsignedInteger('warehouses_checked')->default(0);
            $t->unsignedInteger('issues_found')->default(0);
            $t->unsignedInteger('issues_opened')->default(0);
            $t->unsignedInteger('issues_resolved')->default(0);
            $t->unsignedInteger('issues_reopened')->default(0);
            $t->json('summary')->nullable();              // KPI chụp tại lần chạy (legacy, quy tắc chưa triển khai…)
            $t->text('error')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['status', 'id']);
        });

        Schema::create('inventory_integrity_issues', function (Blueprint $t) {
            $t->id();
            $t->string('issue_key', 191)->unique();       // rule|p|w|ref — ổn định giữa các lần audit
            $t->string('rule_code', 40);
            $t->string('source', 10)->default('auto');    // auto (quy tắc) | policy (chủ đề chính sách)
            $t->string('category', 20);                   // TECHNICAL|ACCOUNTING|BUSINESS|POLICY_DECISION|PENDING_EVIDENCE|EXPECTED
            $t->string('severity', 10);                   // high|medium|low|info
            $t->string('status', 30)->default('OPEN');
            $t->boolean('is_legacy')->default(false);
            $t->unsignedBigInteger('product_id')->nullable()->index();
            $t->unsignedBigInteger('warehouse_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->nullable()->index();
            $t->string('document_ref', 60)->nullable()->index();
            $t->text('document_codes')->nullable();
            $t->string('value_basis', 30)->nullable();    // cơ sở đối chiếu giá trị — chỉ cộng cùng cơ sở
            $t->decimal('qty_book', 18, 3)->nullable();
            $t->decimal('qty_avco', 18, 3)->nullable();
            $t->decimal('qty_lot', 18, 3)->nullable();
            $t->decimal('qty_available', 18, 3)->nullable();
            $t->decimal('qty_diff', 18, 3)->nullable();
            $t->decimal('value_book', 18, 2)->nullable();
            $t->decimal('value_compare', 18, 2)->nullable();
            $t->decimal('value_diff', 18, 2)->nullable();
            $t->text('cause')->nullable();
            $t->json('evidence')->nullable();
            $t->text('proposal')->nullable();
            $t->json('case_info')->nullable();            // phương án/ảnh hưởng do kế toán/DEV nhập (không hardcode)
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_date')->nullable();
            $t->text('request_note')->nullable();
            $t->json('resolution')->nullable();           // kết quả kiểm tra, nguyên nhân, phương án chọn, chứng từ/JE điều chỉnh
            $t->timestampTz('detected_at');
            $t->timestampTz('last_checked_at')->nullable();
            $t->unsignedBigInteger('first_run_id')->nullable();
            $t->unsignedBigInteger('last_seen_run_id')->nullable();
            $t->unsignedBigInteger('resolved_run_id')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->unsignedInteger('reopen_count')->default(0);
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('accepted_at')->nullable();
            $t->json('accepted_snapshot')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'category']);
        });

        Schema::create('inventory_integrity_issue_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained('inventory_integrity_issues')->cascadeOnDelete();
            $t->unsignedBigInteger('run_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 30);                     // detected|assigned|status|note|resolution|reopened|auto_resolved|accepted
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->text('note')->nullable();
            $t->json('payload')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['issue_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_integrity_issue_logs');
        Schema::dropIfExists('inventory_integrity_issues');
        Schema::dropIfExists('inventory_audit_runs');
    }
};
