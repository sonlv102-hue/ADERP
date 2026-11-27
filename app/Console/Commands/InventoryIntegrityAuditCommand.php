<?php

namespace App\Console\Commands;

use App\Models\InventoryIntegrityIssue;
use App\Services\Inventory\Integrity\InventoryIntegrityAuditor;
use Illuminate\Console\Command;

/** Chạy audit "Kiểm soát sai lệch tồn kho và kế toán" (chỉ đọc dữ liệu nghiệp vụ, ghi bảng vấn đề). */
class InventoryIntegrityAuditCommand extends Command
{
    protected $signature = 'inventory:integrity-audit
                            {--scope=all : all|product|warehouse|project|document|issue}
                            {--value= : ID/mã sản phẩm, ID kho, ID/mã dự án, mã chứng từ hoặc ID vấn đề}';

    protected $description = 'Kiểm tra sai lệch tồn kho/kế toán và cập nhật báo cáo kiểm soát (READ-ONLY dữ liệu nghiệp vụ)';

    public function handle(InventoryIntegrityAuditor $auditor): int
    {
        $run = $auditor->run($this->option('scope'), $this->option('value'));
        if ($run->status !== 'success') {
            $this->error("Run #{$run->id} thất bại: {$run->error}");
            return self::FAILURE;
        }
        $this->info(sprintf('Run #%d (%s): %d SP · %d kho · %d dòng — phát hiện %d, mới %d, tự đóng %d, mở lại %d',
            $run->id, $run->rules_version, $run->products_checked, $run->warehouses_checked, $run->rows_checked,
            $run->issues_found, $run->issues_opened, $run->issues_resolved, $run->issues_reopened));
        $this->table(['Quy tắc', 'Phân loại', 'Đang mở'], InventoryIntegrityIssue::whereNotIn('status', InventoryIntegrityIssue::CLOSED)
            ->selectRaw('rule_code, category, COUNT(*) n')->groupBy('rule_code', 'category')->orderBy('rule_code')->get()
            ->map(fn ($r) => [$r->rule_code, $r->category, $r->n])->all());
        return self::SUCCESS;
    }
}
