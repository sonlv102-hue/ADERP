<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectWipEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectExtraCostsAuditJournals extends Command
{
    protected $signature = 'project-extra-costs:audit-journals
                            {--project= : Mã dự án (vd: DA-0001), bỏ trống để audit tất cả}
                            {--fix-status : Cập nhật expense.status=posted khi có JE hợp lệ (thận trọng)}';

    protected $description = 'Audit tính nhất quán của chi phí PS: JE, WIP, supplier, VAT, kết chuyển 154';

    private array $issues = [];
    private int $ok = 0;

    public function handle(): int
    {
        $projectCode = $this->option('project');

        $query = Project::query();
        if ($projectCode) {
            $query->where('code', $projectCode);
        }

        $projects = $query->get();
        if ($projects->isEmpty()) {
            $this->error('Không tìm thấy dự án' . ($projectCode ? " '{$projectCode}'" : ''));
            return self::FAILURE;
        }

        foreach ($projects as $project) {
            $this->auditProject($project);
        }

        $this->printSummary();
        return empty($this->issues) ? self::SUCCESS : self::FAILURE;
    }

    private function auditProject(Project $project): void
    {
        $this->info("=== Dự án: {$project->code} — {$project->name} ===");

        $expenses = ProjectExpense::where('project_id', $project->id)->get();

        if ($expenses->isEmpty()) {
            $this->line('  Không có chi phí PS.');
            return;
        }

        foreach ($expenses as $e) {
            $this->auditExpense($project, $e);
        }
    }

    private function auditExpense(Project $project, ProjectExpense $expense): void
    {
        $prefix = "  [{$expense->id}] {$expense->description}";
        $status = $expense->status ?? 'posted';

        if ($status === 'cancelled') {
            $this->ok++;
            return; // Cancelled — bỏ qua
        }

        $je = $expense->journal_entry_id
            ? JournalEntry::with('lines')->find($expense->journal_entry_id)
            : null;

        // J1: Posted nhưng không có JE
        if ($status === 'posted' && !$je) {
            $this->flag($prefix, 'J1', 'Posted nhưng thiếu journal_entry_id');
        }

        if ($je) {
            $lines = $je->lines;

            // J2: JE không cân
            $totalDebit  = (int) $lines->sum('debit');
            $totalCredit = (int) $lines->sum('credit');
            if ($totalDebit !== $totalCredit) {
                $this->flag($prefix, 'J2', "JE {$je->code} không cân: Nợ={$totalDebit} Có={$totalCredit}");
            }

            // J3: Có 3311 nhưng thiếu supplier_id
            $hasCr3311 = $lines->contains(fn ($l) => str_starts_with((string)$l->account_code, '3311') && $l->credit > 0);
            if ($hasCr3311 && !$expense->supplier_id) {
                $this->flag($prefix, 'J3', "Có TK 3311 nhưng supplier_id = null");
            }

            // J4: Có VAT nhưng thiếu dòng Nợ 1331
            $hasVat  = ($expense->vat_amount ?? 0) > 0;
            $has1331 = $lines->contains(fn ($l) => str_starts_with((string)$l->account_code, '1331') && $l->debit > 0);
            if ($hasVat && !$has1331) {
                $this->flag($prefix, 'J4', "vat_amount > 0 nhưng không có dòng Nợ 1331 trong JE {$je->code}");
            }

            // J5: Nợ 154 nhưng thiếu project_id trên dòng JE
            $has154 = $lines->contains(fn ($l) => str_starts_with((string)$l->account_code, '154') && $l->debit > 0);
            if ($has154) {
                $missingProject = $lines->where('debit', '>', 0)
                    ->filter(fn ($l) => str_starts_with((string)$l->account_code, '154'))
                    ->filter(fn ($l) => !$l->project_id)
                    ->count();
                if ($missingProject > 0) {
                    $this->flag($prefix, 'J5', "JE {$je->code}: {$missingProject} dòng Nợ 154 thiếu project_id");
                }
            }
        }

        // W1: WIP cũ (non-154 expense có WIP entry từ code cũ) nhưng không có JE kết chuyển
        $debitAcct = $expense->debit_account ?? '';
        $isDirectTo154 = str_starts_with($debitAcct, '154');
        $directWip = ProjectWipEntry::where('source_type', ProjectExpense::class)
            ->where('source_id', $expense->id)
            ->first();

        $postedTransfers = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->count();

        if (!$isDirectTo154 && $directWip && $postedTransfers === 0) {
            $this->flag($prefix, 'W1', "TK Nợ={$debitAcct} không phải 154 nhưng có WIP entry #{$directWip->id} (code cũ), chưa có JE kết chuyển");
        }

        // W2: TK 154 nhưng không có WIP entry và JE exists
        if ($isDirectTo154 && !$directWip && $je) {
            $this->flag($prefix, 'W2', "TK Nợ=154 nhưng thiếu ProjectWipEntry (WIP chưa được tạo)");
        }

        // T1: JE kết chuyển có nhưng thiếu WIP (transfer posted nhưng không có wip_entry_id)
        $transfersWithoutWip = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->whereNull('project_wip_entry_id')
            ->count();
        if ($transfersWithoutWip > 0) {
            $this->flag($prefix, 'T1', "{$transfersWithoutWip} transfer posted nhưng project_wip_entry_id = null");
        }

        // T2: Tổng kết chuyển > số tiền expense (double-count)
        $totalTransferred = (int) ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->sum('amount');
        $expenseAmt = (int) round((float) $expense->amount);
        if ($totalTransferred > $expenseAmt) {
            $overshoot = $totalTransferred - $expenseAmt;
            $this->flag($prefix, 'T2', "Đã KC {$totalTransferred} > expense {$expenseAmt} (vượt " . number_format($overshoot) . " VND)");
        }

        // T3: Kết chuyển về 3311/111x/112x (sai — phải KC sang TK Nợ gốc)
        $badTransfers = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->where(fn ($q) => $q->where('credit_account', 'like', '3311%')
                ->orWhere('credit_account', 'like', '111%')
                ->orWhere('credit_account', 'like', '112%'))
            ->count();
        if ($badTransfers > 0) {
            $this->flag($prefix, 'T3', "{$badTransfers} transfer KC vào TK tiền/công nợ (sai — phải KC vào TK Nợ gốc)");
        }

        if (!array_key_exists($expense->id, array_column($this->issues, 0, 0))) {
            $this->ok++;
        }
    }

    private function flag(string $prefix, string $code, string $message): void
    {
        $this->issues[] = compact('prefix', 'code', 'message');
        $this->warn("  ⚠ [{$code}] {$message}");
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════════════');
        $this->info("Kết quả audit chi phí PS:");
        $this->line("  ✓ OK:     {$this->ok}");
        $this->line("  ⚠ Issues: " . count($this->issues));

        if (!empty($this->issues)) {
            $this->newLine();
            $this->line('Chi tiết lỗi:');
            $grouped = collect($this->issues)->groupBy('code');
            foreach ($grouped as $code => $items) {
                $this->warn("  [{$code}] × " . $items->count() . " — " . $items->first()['message']);
            }
            $this->newLine();
            $this->comment('Dùng php artisan project-extra-costs:repair-transfer-status --project=<code> --dry-run để xem repair plan.');
        } else {
            $this->info('Không phát hiện vấn đề.');
        }
    }
}
