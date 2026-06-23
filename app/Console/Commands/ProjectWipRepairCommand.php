<?php

namespace App\Console\Commands;

use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\StockExit;
use Illuminate\Console\Command;

/**
 * Repair WIP entries với các vấn đề nhất định.
 *
 * Chỉ repair orphan / cancelled-source entries (W1, W2, W6).
 * Không tạo lại các dòng đã bị user hủy có lý do (status=cancelled với cancel_reason không rỗng).
 *
 * Usage:
 *   php artisan projects:wip-repair --project=DA-0001 --dry-run
 *   php artisan projects:wip-repair --project=DA-0001 --apply
 */
class ProjectWipRepairCommand extends Command
{
    protected $signature = 'projects:wip-repair
                            {--project= : Lọc theo project_id hoặc code (DA-0001)}
                            {--dry-run  : Hiển thị kế hoạch, không ghi DB (mặc định)}
                            {--apply    : Thực sự ghi vào DB}';

    protected $description = 'Repair orphan/stale WIP entries (W1, W2, W6). Tôn trọng dòng đã hủy có lý do.';

    private bool $dryRun = true;
    private int  $repaired = 0;
    private int  $skipped  = 0;

    public function handle(): int
    {
        $this->dryRun = !$this->option('apply');
        $projectFilter = $this->option('project');
        $mode = $this->dryRun ? '[DRY-RUN]' : '[APPLY]';

        $this->info("=== WIP Repair {$mode} ===");
        if ($projectFilter) {
            $this->line("Project filter: {$projectFilter}");
        }
        $this->newLine();

        $query = ProjectWipEntry::query()
            ->with(['journalEntry', 'project'])
            ->where('status', 'active');

        if ($projectFilter) {
            if (is_numeric($projectFilter)) {
                $query->where('project_id', (int) $projectFilter);
            } else {
                $query->whereHas('project', fn ($q) => $q->where('code', $projectFilter));
            }
        }

        foreach ($query->get() as $entry) {
            $this->repairEntry($entry);
        }

        $this->newLine();
        $this->info("Kết quả: repaired={$this->repaired}, skipped={$this->skipped}");

        if ($this->dryRun) {
            $this->comment('Chạy với --apply để áp dụng.');
        }

        return self::SUCCESS;
    }

    private function repairEntry(ProjectWipEntry $entry): void
    {
        $sourceType = $entry->source_type ?? '';

        if ($sourceType === StockExit::class || $sourceType === 'App\\Models\\StockExit') {
            $this->repairStockExitEntry($entry);
        } elseif ($sourceType === PurchaseInvoice::class || $sourceType === 'App\\Models\\PurchaseInvoice') {
            $this->repairPurchaseInvoiceEntry($entry);
        }
    }

    private function repairStockExitEntry(ProjectWipEntry $entry): void
    {
        if (!StockExit::where('id', $entry->source_id)->exists()) {
            $this->cancelOrphan($entry, 'W1', "StockExit #{$entry->source_id} không còn tồn tại");
            return;
        }

        $exit = StockExit::find($entry->source_id);
        if ($exit && $exit->status?->value === 'cancelled') {
            $this->cancelOrphan($entry, 'W2', "StockExit #{$entry->source_id} ({$exit->code}) đã cancelled");
        }
    }

    private function repairPurchaseInvoiceEntry(ProjectWipEntry $entry): void
    {
        if (!PurchaseInvoice::where('id', $entry->source_id)->exists()) {
            $this->cancelOrphan($entry, 'W6', "PurchaseInvoice #{$entry->source_id} không còn tồn tại");
        }
    }

    private function cancelOrphan(ProjectWipEntry $entry, string $code, string $reason): void
    {
        if (!is_null($entry->journal_entry_id)) {
            $this->warn("  [{$code}] wip#{$entry->id} — SKIP: có journal_entry_id={$entry->journal_entry_id}, cần hủy bút toán thủ công trước.");
            $this->skipped++;
            return;
        }

        $this->line("  [{$code}] wip#{$entry->id} project#{$entry->project_id} — {$reason} (amount=" . number_format($entry->amount) . ')');

        if (!$this->dryRun) {
            $entry->update([
                'status'        => 'cancelled',
                'cancel_reason' => "[auto-repair {$code}] {$reason}",
                'cancelled_at'  => now(),
            ]);
            $this->info("    → Đã hủy wip#{$entry->id}");
        } else {
            $this->comment('    → [DRY-RUN] Sẽ hủy wip#' . $entry->id);
        }

        $this->repaired++;
    }
}
