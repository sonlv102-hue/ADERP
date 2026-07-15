<?php

namespace App\Console\Commands;

use App\Enums\SubcontractCostGroup;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Services\AccountingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair riêng cho các bút toán thủ công cũ (như G2: JE 999-1004) đang thiếu project_id/cost_group
 * trên dòng Nợ TK154. KHÔNG tự động gán — bắt buộc chỉ định rõ --je, --project, --cost-group.
 * Mặc định là dry-run; phải thêm --apply mới ghi dữ liệu.
 */
class JournalEntryRepairLegacyProjectWipCommand extends Command
{
    protected $signature = 'journal-entries:repair-legacy-project-wip
                            {--je= : Danh sách journal_entry_id cách nhau bởi dấu phẩy (bắt buộc, không xử lý toàn bộ)}
                            {--project= : Mã dự án (code, vd DA-0001) để gán cho các dòng (bắt buộc)}
                            {--cost-group= : Nhóm chi phí: material|labor|subcontractor|equipment|transport|overhead|other (bắt buộc)}
                            {--apply : Áp dụng thật. Không có flag này = chỉ xem trước (dry-run)}';

    protected $description = 'Gán project_id/cost_group cho JE thủ công cũ thiếu chiều dự án (vd 6 JE G2) — cần xác nhận rõ ràng, không tự đoán.';

    public function handle(AccountingService $accounting): int
    {
        $jeOption = $this->option('je');
        $projectCode = $this->option('project');
        $costGroupOption = $this->option('cost-group');
        $apply = $this->option('apply');

        if (! $jeOption) {
            $this->error('Bắt buộc chỉ định --je=id1,id2,... — command này KHÔNG xử lý toàn bộ để tránh gán nhầm.');
            return 1;
        }
        if (! $projectCode) {
            $this->error('Bắt buộc chỉ định --project=<mã dự án>.');
            return 1;
        }
        if (! $costGroupOption || ! SubcontractCostGroup::tryFrom($costGroupOption)) {
            $this->error('Bắt buộc chỉ định --cost-group hợp lệ: ' . implode(',', array_column(SubcontractCostGroup::cases(), 'value')));
            return 1;
        }

        $project = Project::where('code', $projectCode)->first();
        if (! $project) {
            $this->error("Không tìm thấy dự án có mã {$projectCode}.");
            return 1;
        }

        $jeIds = array_filter(array_map('trim', explode(',', $jeOption)));

        $this->info($apply ? '=== ÁP DỤNG THẬT ===' : '=== DRY RUN — chỉ xem trước, chưa ghi gì ===');
        $this->line("Dự án đích: {$project->code} - {$project->name}");
        $this->line("Nhóm chi phí: {$costGroupOption}");
        $this->newLine();

        $touched = 0;

        foreach ($jeIds as $jeId) {
            $entry = JournalEntry::with('lines')->find($jeId);

            if (! $entry) {
                $this->warn("JE #{$jeId}: không tồn tại — bỏ qua.");
                continue;
            }
            if ($entry->status !== 'posted') {
                $this->warn("JE #{$jeId} ({$entry->code}): status={$entry->status}, chỉ xử lý JE đã posted — bỏ qua.");
                continue;
            }
            if ($entry->reference_type !== null) {
                $this->warn("JE #{$jeId} ({$entry->code}): reference_type={$entry->reference_type} — đây không phải Phiếu kế toán thủ công, bỏ qua để tránh đụng vào bút toán tự động.");
                continue;
            }

            $targetLines = $entry->lines->filter(
                fn ($l) => str_starts_with($l->account_code, '154')
                    && (float) $l->debit > 0
                    && (! $l->project_id || ! $l->cost_group)
            );

            if ($targetLines->isEmpty()) {
                $this->line("JE #{$jeId} ({$entry->code}): không có dòng Nợ 154 nào thiếu project_id/cost_group — bỏ qua.");
                continue;
            }

            $this->line("─── JE #{$jeId} ({$entry->code}, {$entry->entry_date->format('Y-m-d')}, {$entry->description})");
            foreach ($targetLines as $line) {
                $this->line("   Dòng #{$line->id} TK{$line->account_code} Nợ=" . number_format($line->debit)
                    . " | project_id: " . ($line->project_id ?? 'NULL') . " → {$project->id} ({$project->code})"
                    . " | cost_group: " . ($line->cost_group ?? 'NULL') . " → {$costGroupOption}");
            }
            $touched++;

            if ($apply) {
                DB::transaction(function () use ($targetLines, $project, $costGroupOption, $entry, $accounting) {
                    foreach ($targetLines as $line) {
                        $line->update(['project_id' => $project->id, 'cost_group' => $costGroupOption]);
                    }
                    $accounting->createWipForManualEntry($entry->fresh());
                });
                $this->info("   → Đã ghi + tạo WIP cho JE #{$jeId}.");
            }
        }

        $this->newLine();
        if (! $apply) {
            $this->warn("{$touched} JE sẽ bị ảnh hưởng. Thêm --apply để ghi dữ liệu thật (sau khi đã xác nhận đúng dự án/nhóm chi phí).");
        } else {
            $this->info("Đã xử lý {$touched} JE.");
        }

        return 0;
    }
}
