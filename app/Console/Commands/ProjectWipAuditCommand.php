<?php

namespace App\Console\Commands;

use App\Models\JournalEntryLine;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Services\AccountingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra tính nhất quán WIP entries TK154 theo dự án.
 *
 * Checks:
 *  W1 — WIP active nhưng source StockExit không còn tồn tại (orphan)
 *  W2 — WIP active nhưng source StockExit đã bị cancelled
 *  W3 — WIP active với journal_entry_id null (chưa hạch toán)
 *  W4 — WIP active nhưng journal_entry đã reversed/voided
 *  W5 — Tổng WIP active theo project khớp với GL TK154 hay không
 */
class ProjectWipAuditCommand extends Command
{
    protected $signature = 'projects:wip-audit
                            {--project= : Lọc theo project_id}
                            {--fix : Xóa orphan W1/W2 (chỉ khi source đã xóa và không có JE)}';

    protected $description = 'Kiểm tra tính nhất quán WIP entries TK154 của dự án (W1–W5).';

    private array $issues = [];

    public function handle(): int
    {
        $projectId = $this->option('project') ? (int) $this->option('project') : null;
        $fix       = $this->option('fix');
        $wipAccount = AccountingSettings::get('project_wip_account', '154');

        $this->info('=== WIP Audit TK' . $wipAccount . ' ===');
        if ($projectId) {
            $this->line("Project filter: #$projectId");
        }
        $this->newLine();

        $query = ProjectWipEntry::query()
            ->with(['journalEntry', 'project'])
            ->where('status', 'active');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $entries = $query->get();

        foreach ($entries as $entry) {
            $this->checkEntry($entry, $wipAccount, $fix);
        }

        // W5 — GL reconcile per project
        $this->checkGlReconcile($wipAccount, $projectId);

        // Summary
        $this->newLine();
        if (empty($this->issues)) {
            $this->info('✓ Không phát hiện vấn đề nào.');
            return 0;
        }

        $this->error("Tổng: " . count($this->issues) . " vấn đề phát hiện.");
        foreach ($this->issues as $issue) {
            $this->line("  [{$issue['code']}] wip#{$issue['wip_id']} project#{$issue['project_id']} {$issue['message']}");
        }
        return 1;
    }

    private function checkEntry(ProjectWipEntry $entry, string $wipAccount, bool $fix): void
    {
        if ($entry->source_type !== StockExit::class && $entry->source_type !== 'App\\Models\\StockExit') {
            return; // chỉ audit StockExit source
        }

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

        // W3 — không có journal_entry_id
        if (is_null($entry->journal_entry_id)) {
            $this->addIssue('W3', $entry, "Không có bút toán GL (journal_entry_id null). Chưa hạch toán vào TK{$wipAccount}.");
        }

        // W4 — journal entry đã reversed/voided
        if ($entry->journalEntry && in_array($entry->journalEntry->status, ['reversed', 'voided'])) {
            $this->addIssue('W4', $entry, "Bút toán {$entry->journalEntry->code} đã {$entry->journalEntry->status} nhưng WIP vẫn active.");
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

        $glBalances  = $glQuery->groupBy('journal_entry_lines.project_id')->get()->keyBy('project_id');
        $wipTotals   = $wipQuery->groupBy('project_id')->get()->keyBy('project_id');

        $allProjects = $glBalances->keys()->merge($wipTotals->keys())->unique();

        $hasW5 = false;
        foreach ($allProjects as $pid) {
            $gl  = (float) ($glBalances[$pid]?->gl_balance ?? 0);
            $wip = (float) ($wipTotals[$pid]?->wip_total ?? 0);
            $diff = abs($gl - $wip);

            if ($diff > 1) {
                $this->addIssue('W5', new ProjectWipEntry(['project_id' => $pid, 'id' => 0]), sprintf(
                    "GL TK%s = %s, WIP total = %s, chênh lệch = %s",
                    $wipAccount,
                    number_format($gl),
                    number_format($wip),
                    number_format($diff)
                ));
                $hasW5 = true;
            }
        }

        if (!$hasW5) {
            $this->info("  ✓ GL TK{$wipAccount} khớp với WIP active cho " . $allProjects->count() . " dự án.");
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
        $this->warn("  [$code] wip#{$entry->id} project#{$entry->project_id} — $message");
    }
}
