<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rà soát trạng thái kết chuyển chi phí PS → TK 154.
 *
 * Checks:
 *  T1 — Chi phí có bút toán gốc chưa
 *  T2 — Chi phí đã kết chuyển (transfer records)
 *  T3 — Chi phí có WIP legacy (trước hệ thống transfer)
 *  T4 — Chi phí đã trực tiếp vào TK 154
 *  T5 — Số dư kết chuyển khớp GL TK 154 không
 *  T6 — WIP entry orphan (transfer bị cancelled nhưng WIP vẫn active)
 */
class ProjectExtraCostAuditCommand extends Command
{
    protected $signature = 'project-extra-costs:audit-transfer-to-154
                            {--project= : Mã dự án, ví dụ DA-0001}
                            {--all : Rà soát tất cả dự án}';

    protected $description = 'Rà soát trạng thái kết chuyển chi phí PS sang TK 154 (T1–T6).';

    public function handle(): int
    {
        $projectCode = $this->option('project');
        $all         = $this->option('all');

        if (!$projectCode && !$all) {
            $this->error('Vui lòng truyền --project=DA-xxxx hoặc --all.');
            return 1;
        }

        $query = Project::query();
        if ($projectCode) {
            $query->where('code', $projectCode);
        }

        $projects = $query->get();
        if ($projects->isEmpty()) {
            $this->error("Không tìm thấy dự án: {$projectCode}");
            return 1;
        }

        $totalIssues = 0;

        foreach ($projects as $project) {
            $this->line('');
            $this->info("=== Dự án: {$project->code} — {$project->name} ===");

            $expenses = ProjectExpense::where('project_id', $project->id)->orderBy('id')->get();
            if ($expenses->isEmpty()) {
                $this->line('  (Không có chi phí PS)');
                continue;
            }

            // Lấy dữ liệu liên quan
            $expenseIds = $expenses->pluck('id');

            $jeByExpenseId = JournalEntry::where('reference_type', ProjectExpense::class)
                ->whereIn('reference_id', $expenseIds)
                ->whereIn('status', ['draft', 'posted'])
                ->pluck('id', 'reference_id');

            $transfersByExpenseId = ProjectExtraCostTransfer::whereIn('project_expense_id', $expenseIds)
                ->get()
                ->groupBy('project_expense_id');

            $wipByExpenseId = ProjectWipEntry::where('project_id', $project->id)
                ->where('source_type', ProjectExpense::class)
                ->whereIn('source_id', $expenseIds)
                ->get()
                ->keyBy('source_id');

            // Tổng số tiền đã kết chuyển qua GL TK154
            $gl154Total = (int) JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.reference_type', ProjectExtraCostTransfer::class)
                ->where('journal_entry_lines.account_code', 'LIKE', '154%')
                ->whereIn('journal_entry_lines.project_id', [$project->id])
                ->where('journal_entries.status', 'posted')
                ->sum('journal_entry_lines.debit');

            // WIP active từ transfer records
            $wipFromTransfer = (int) ProjectWipEntry::where('project_id', $project->id)
                ->where('source_type', ProjectExtraCostTransfer::class)
                ->where('status', 'active')
                ->sum('amount');

            $issueCount = 0;
            $rows = [];

            foreach ($expenses as $e) {
                $debitAcct       = $e->debit_account ?? '(không có)';
                $isDirectTo154   = str_starts_with((string) $e->debit_account, '154');
                $hasJe           = $jeByExpenseId->has($e->id);
                $transfers       = $transfersByExpenseId->get($e->id, collect());
                $postedTransfers = $transfers->where('status', 'posted');
                $cancelledTransfers = $transfers->where('status', 'cancelled');
                $directWip       = $wipByExpenseId->get($e->id);
                $transferredAmt  = (int) $postedTransfers->sum('amount');
                $expenseAmt      = (int) round((float) $e->amount);
                $remainingAmt    = max(0, $expenseAmt - $transferredAmt);

                // Trạng thái
                $status = match (true) {
                    $isDirectTo154                  => 'direct_154',
                    (bool) $directWip && $transfers->isEmpty() => 'legacy_wip',
                    $transferredAmt === 0           => 'none',
                    $transferredAmt >= $expenseAmt  => 'full',
                    default                         => 'partial',
                };

                // Phát hiện vấn đề
                $issues = [];

                // T1: không có JE gốc và chưa kết chuyển
                if (!$hasJe && !$isDirectTo154 && $transfers->isEmpty()) {
                    $issues[] = 'T1: Chưa có bút toán gốc';
                }

                // T3: WIP legacy — không thể kết chuyển qua hệ thống mới
                if ($directWip && $transfers->isEmpty()) {
                    $hasWipJe = (bool) $directWip->journal_entry_id;
                    $has154Line = false;
                    if ($hasWipJe) {
                        $has154Line = JournalEntryLine::where('journal_entry_id', $directWip->journal_entry_id)
                            ->where('account_code', 'LIKE', '154%')
                            ->exists();
                    }
                    if ($has154Line) {
                        $issues[] = 'T3: WIP legacy — đã có bút toán N154 (không cần kết chuyển lại)';
                    } else {
                        $issues[] = 'T3: WIP legacy — chưa xác định bút toán N154, cần kiểm tra thủ công';
                    }
                }

                // T6: có transfer bị cancelled nhưng WIP vẫn active
                if ($cancelledTransfers->isNotEmpty()) {
                    foreach ($cancelledTransfers as $ct) {
                        $wipStillActive = $ct->project_wip_entry_id
                            && ProjectWipEntry::where('id', $ct->project_wip_entry_id)->where('status', 'active')->exists();
                        if ($wipStillActive) {
                            $issues[] = "T6: Transfer #{$ct->id} đã hủy nhưng WIP entry #{$ct->project_wip_entry_id} vẫn active";
                        }
                    }
                }

                $rows[] = [
                    'id'          => $e->id,
                    'description' => mb_substr($e->description, 0, 30),
                    'debit_acct'  => $debitAcct,
                    'amount'      => number_format($expenseAmt),
                    'status'      => $status,
                    'transferred' => number_format($transferredAmt),
                    'remaining'   => number_format($remainingAmt),
                    'je_count'    => $hasJe ? 1 : 0,
                    'transfer_cnt'=> $postedTransfers->count(),
                    'issues'      => implode('; ', $issues) ?: '—',
                ];

                if ($issues) {
                    $issueCount += count($issues);
                }
            }

            // Hiển thị bảng
            $this->table(
                ['ID', 'Mô tả', 'TK Nợ', 'Số tiền', 'Trạng thái', 'Đã KC', 'Còn lại', 'BT gốc', 'KC count', 'Vấn đề'],
                array_map(fn ($r) => [
                    $r['id'], $r['description'], $r['debit_acct'],
                    $r['amount'], $r['status'],
                    $r['transferred'], $r['remaining'],
                    $r['je_count'], $r['transfer_cnt'], $r['issues'],
                ], $rows)
            );

            // T5: Kiểm tra GL TK154 có khớp WIP không
            $this->line("  GL TK154 (từ transfer): " . number_format($gl154Total));
            $this->line("  WIP active (từ transfer): " . number_format($wipFromTransfer));
            if ($gl154Total !== $wipFromTransfer) {
                $this->warn("  ⚠ T5: GL TK154 ($gl154Total) ≠ WIP active ($wipFromTransfer) — lệch " . number_format(abs($gl154Total - $wipFromTransfer)));
                $issueCount++;
            } else {
                $this->line("  ✓ T5: GL TK154 khớp WIP active.");
            }

            if ($issueCount === 0) {
                $this->info("  ✓ Không phát hiện vấn đề.");
            } else {
                $this->warn("  ⚠ Phát hiện {$issueCount} vấn đề cần kiểm tra.");
                $totalIssues += $issueCount;
            }
        }

        $this->line('');
        if ($totalIssues === 0) {
            $this->info('=== Kết quả: Không có vấn đề nào ===');
        } else {
            $this->warn("=== Kết quả: Tổng {$totalIssues} vấn đề cần xử lý ===");
        }

        return $totalIssues > 0 ? 1 : 0;
    }
}
