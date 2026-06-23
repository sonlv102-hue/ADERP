<?php

namespace App\Console\Commands;

use App\Models\JournalEntryLine;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockExit;
use App\Services\AccountingSettings;
use Illuminate\Console\Command;

/**
 * Kiểm tra tính nhất quán WIP entries TK154 theo dự án.
 *
 * Checks:
 *  W1 — StockExit source: source không còn tồn tại (orphan)
 *  W2 — StockExit source: source đã cancelled
 *  W3 — WIP active nhưng journal_entry_id null (chưa hạch toán) [tất cả sources]
 *  W4 — WIP active nhưng journal_entry đã reversed/voided [tất cả sources]
 *  W5 — Tổng WIP active theo project khớp với GL TK154 hay không
 *  W6 — PurchaseInvoice source: invoice không còn tồn tại (orphan)
 *  W7 — PurchaseInvoice source: source_item không còn project_id/TK154 (reclassified)
 */
class ProjectWipAuditCommand extends Command
{
    protected $signature = 'projects:wip-audit
                            {--project= : Lọc theo project_id hoặc code (DA-0001)}
                            {--fix      : Xóa orphan W1/W6 không có JE (dùng cẩn thận)}';

    protected $description = 'Kiểm tra tính nhất quán WIP entries TK154 (W1–W7).';

    private array $issues = [];

    public function handle(): int
    {
        $projectFilter = $this->option('project');
        $fix           = $this->option('fix');
        $wipAccount    = AccountingSettings::get('project_wip_account', '154');

        $this->info('=== WIP Audit TK' . $wipAccount . ' ===');
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

        $entries = $query->get();
        $projectId = null;

        foreach ($entries as $entry) {
            if ($projectFilter && !is_numeric($projectFilter) && !$projectId) {
                $projectId = $entry->project_id;
            }
            $this->checkEntry($entry, $wipAccount, $fix);
        }

        // Resolve numeric project_id for W5 filter
        if ($projectFilter && is_numeric($projectFilter)) {
            $projectId = (int) $projectFilter;
        }

        $this->checkGlReconcile($wipAccount, $projectId);

        $this->newLine();
        if (empty($this->issues)) {
            $this->info('✓ Không phát hiện vấn đề nào.');
            return 0;
        }

        $this->error('Tổng: ' . count($this->issues) . ' vấn đề phát hiện.');
        foreach ($this->issues as $issue) {
            $this->line("  [{$issue['code']}] wip#{$issue['wip_id']} project#{$issue['project_id']} {$issue['message']}");
        }
        return 1;
    }

    private function checkEntry(ProjectWipEntry $entry, string $wipAccount, bool $fix): void
    {
        $sourceType = $entry->source_type ?? '';

        // W3 — không có journal_entry_id (áp dụng cho mọi nguồn)
        if (is_null($entry->journal_entry_id)) {
            $this->addIssue('W3', $entry, "Không có bút toán GL (journal_entry_id null). Chưa hạch toán vào TK{$wipAccount}.");
        }

        // W4 — journal entry đã reversed/voided (áp dụng cho mọi nguồn)
        if ($entry->journalEntry && in_array($entry->journalEntry->status, ['reversed', 'voided'])) {
            $this->addIssue('W4', $entry, "Bút toán {$entry->journalEntry->code} đã {$entry->journalEntry->status} nhưng WIP vẫn active.");
        }

        if ($sourceType === StockExit::class || $sourceType === 'App\\Models\\StockExit') {
            $this->checkStockExitEntry($entry, $fix);
        } elseif ($sourceType === PurchaseInvoice::class || $sourceType === 'App\\Models\\PurchaseInvoice') {
            $this->checkPurchaseInvoiceEntry($entry, $fix);
        }
    }

    private function checkStockExitEntry(ProjectWipEntry $entry, bool $fix): void
    {
        $sourceExists = StockExit::where('id', $entry->source_id)->exists();

        // W1 — source không còn tồn tại
        if (!$sourceExists) {
            $this->addIssue('W1', $entry, "Source StockExit #{$entry->source_id} không còn tồn tại (orphan). Amount: " . number_format($entry->amount));
            if ($fix && is_null($entry->journal_entry_id)) {
                $entry->delete();
                $this->warn("  → W1 auto-fix: đã xóa orphan wip#{$entry->id}");
            }
            return;
        }

        $exit = StockExit::find($entry->source_id);

        // W2 — source đã cancelled
        if ($exit && $exit->status?->value === 'cancelled') {
            $this->addIssue('W2', $entry, "Source StockExit #{$entry->source_id} ({$exit->code}) đã cancelled nhưng WIP vẫn active.");
            if ($fix && is_null($entry->journal_entry_id)) {
                $entry->delete();
                $this->warn("  → W2 auto-fix: đã xóa wip#{$entry->id} (không có JE)");
            }
        }
    }

    private function checkPurchaseInvoiceEntry(ProjectWipEntry $entry, bool $fix): void
    {
        $invoice = PurchaseInvoice::find($entry->source_id);

        // W6 — invoice không còn tồn tại
        if (!$invoice) {
            $this->addIssue('W6', $entry, "Source PurchaseInvoice #{$entry->source_id} không còn tồn tại (orphan). Amount: " . number_format($entry->amount));
            if ($fix && is_null($entry->journal_entry_id)) {
                $entry->update([
                    'status'       => 'cancelled',
                    'cancel_reason' => '[auto-fix W6] Invoice không còn tồn tại',
                    'cancelled_by' => null,
                    'cancelled_at' => now(),
                ]);
                $this->warn("  → W6 auto-fix: đã hủy wip#{$entry->id}");
            }
            return;
        }

        // W7 — source_item không còn project_id hoặc TK Nợ ≠ 154
        if ($entry->source_item_id) {
            $item = PurchaseInvoiceItem::find($entry->source_item_id);
            if (!$item) {
                $this->addIssue('W7', $entry, "PurchaseInvoiceItem #{$entry->source_item_id} không còn tồn tại.");
            } elseif ((string) ($item->project_id ?? '') !== (string) $entry->project_id) {
                $this->addIssue('W7', $entry, "PurchaseInvoiceItem #{$entry->source_item_id} đã đổi project_id (hiện: {$item->project_id}, WIP: {$entry->project_id}).");
            } elseif (!str_starts_with((string) ($item->account_code ?? '154'), '154')) {
                $this->addIssue('W7', $entry, "PurchaseInvoiceItem #{$entry->source_item_id} TK Nợ = '{$item->account_code}' (không phải 154) nhưng WIP vẫn active.");
            }
        }
    }

    private function checkGlReconcile(string $wipAccount, ?int $projectId): void
    {
        $this->line('─── W5: Đối soát GL TK' . $wipAccount . ' vs WIP tổng ───');

        $glQuery = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.account_code', 'like', $wipAccount . '%')
            ->whereNotNull('journal_entry_lines.project_id')
            ->selectRaw('journal_entry_lines.project_id, SUM(journal_entry_lines.debit - journal_entry_lines.credit) as gl_balance');

        $wipQuery = ProjectWipEntry::query()
            ->where('status', 'active')
            ->selectRaw('project_id, SUM(amount) as wip_total');

        if ($projectId) {
            $glQuery->where('journal_entry_lines.project_id', $projectId);
            $wipQuery->where('project_id', $projectId);
        }

        $glBalances = $glQuery->groupBy('journal_entry_lines.project_id')->get()->keyBy('project_id');
        $wipTotals  = $wipQuery->groupBy('project_id')->get()->keyBy('project_id');

        $allProjects = $glBalances->keys()->merge($wipTotals->keys())->unique();

        $hasW5 = false;
        foreach ($allProjects as $pid) {
            $gl   = (float) ($glBalances[$pid]?->gl_balance ?? 0);
            $wip  = (float) ($wipTotals[$pid]?->wip_total ?? 0);
            $diff = abs($gl - $wip);

            if ($diff > 1) {
                $this->addIssue('W5', new ProjectWipEntry(['project_id' => $pid, 'id' => 0]), sprintf(
                    'GL TK%s = %s, WIP total = %s, chênh lệch = %s',
                    $wipAccount,
                    number_format($gl),
                    number_format($wip),
                    number_format($diff)
                ));
                $hasW5 = true;
            }
        }

        if (!$hasW5) {
            $this->info('  ✓ GL TK' . $wipAccount . ' khớp với WIP active cho ' . $allProjects->count() . ' dự án.');
        }
    }

    private function addIssue(string $code, ProjectWipEntry $entry, string $message): void
    {
        $this->issues[] = [
            'code'       => $code,
            'wip_id'     => $entry->id,
            'project_id' => $entry->project_id,
            'message'    => $message,
        ];
        $this->warn("  [{$code}] wip#{$entry->id} project#{$entry->project_id} — $message");
    }
}
