<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectWipEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectExtraCostsRepairTransferStatus extends Command
{
    protected $signature = 'project-extra-costs:repair-transfer-status
                            {--project= : Mã dự án (bắt buộc)}
                            {--dry-run : Chỉ preview, không thay đổi dữ liệu}
                            {--apply : Áp dụng repair}';

    protected $description = 'Repair trạng thái kết chuyển chi phí PS: map status, tạo WIP thiếu cho transfer đã posted';

    private bool $dryRun = true;
    private array $plan = [];

    public function handle(): int
    {
        $projectCode = $this->option('project');
        if (!$projectCode) {
            $this->error('Bắt buộc truyền --project=<mã dự án>');
            return self::FAILURE;
        }

        $project = Project::where('code', $projectCode)->first();
        if (!$project) {
            $this->error("Không tìm thấy dự án '{$projectCode}'");
            return self::FAILURE;
        }

        $this->dryRun = !$this->option('apply');

        if ($this->dryRun) {
            $this->warn("=== DRY-RUN mode (chỉ xem, không sửa) ===");
            $this->comment("Thêm --apply để áp dụng.\n");
        } else {
            $this->warn("=== APPLY mode — đang sửa dữ liệu ===");
        }

        $this->info("Dự án: {$project->code} — {$project->name}");

        $expenses = ProjectExpense::where('project_id', $project->id)->get();

        foreach ($expenses as $e) {
            $this->repairExpense($project, $e);
        }

        $this->printPlan();

        return self::SUCCESS;
    }

    private function repairExpense(Project $project, ProjectExpense $expense): void
    {
        $status    = $expense->status ?? 'posted';
        $debitAcct = $expense->debit_account ?? '';

        if ($status === 'cancelled') return;

        $postedTransfers = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->get();

        // R1: Tạo WIP entry thiếu cho transfer đã posted
        foreach ($postedTransfers as $transfer) {
            if ($transfer->project_wip_entry_id) continue;

            $this->plan[] = [
                'action'  => 'CREATE_WIP_FOR_TRANSFER',
                'expense' => "#{$expense->id} {$expense->description}",
                'detail'  => "Transfer #{$transfer->id} ({$transfer->transfer_date}) số tiền " . number_format($transfer->amount) . " — thiếu WIP entry",
            ];

            if (!$this->dryRun) {
                DB::transaction(function () use ($project, $expense, $transfer) {
                    $wip = ProjectWipEntry::create([
                        'project_id'          => $project->id,
                        'source_type'         => ProjectExpense::class,
                        'source_id'           => $expense->id,
                        'cost_type'           => $expense->category->wipCostType(),
                        'amount'              => $transfer->amount,
                        'description'         => "Kết chuyển 154 — " . $expense->description,
                        'entry_date'          => $transfer->transfer_date,
                        'journal_entry_id'    => $transfer->journal_entry_id,
                        'transfer_from_entry_id' => $transfer->journal_entry_id,
                        'created_by'          => auth()->id() ?? 1,
                    ]);
                    $transfer->update(['project_wip_entry_id' => $wip->id]);
                });
            }
        }

        // R2: Cập nhật status=posted trên expense draft nếu có JE hợp lệ
        if ($status === 'draft' && $expense->journal_entry_id) {
            $je = \App\Models\JournalEntry::find($expense->journal_entry_id);
            if ($je && $je->status === 'posted') {
                $this->plan[] = [
                    'action'  => 'MARK_EXPENSE_POSTED',
                    'expense' => "#{$expense->id} {$expense->description}",
                    'detail'  => "Expense là draft nhưng JE {$je->code} đã posted → cập nhật status=posted",
                ];
                if (!$this->dryRun) {
                    $expense->updateQuietly(['status' => 'posted']);
                }
            }
        }

        // R3: Tạo WIP thiếu cho expense TK 154 có JE đã posted nhưng không có WIP
        $isDirectTo154 = str_starts_with($debitAcct, '154');
        $hasWip = ProjectWipEntry::where('source_type', ProjectExpense::class)
            ->where('source_id', $expense->id)
            ->where('status', 'active')
            ->exists();

        if ($isDirectTo154 && !$hasWip && $expense->journal_entry_id) {
            $je = \App\Models\JournalEntry::find($expense->journal_entry_id);
            if ($je && $je->status === 'posted') {
                $amount = (int) round((float) $expense->amount);
                $this->plan[] = [
                    'action'  => 'CREATE_MISSING_WIP',
                    'expense' => "#{$expense->id} {$expense->description}",
                    'detail'  => "TK 154 + JE {$je->code} posted nhưng thiếu WIP entry (số tiền " . number_format($amount) . ")",
                ];
                if (!$this->dryRun) {
                    DB::transaction(function () use ($project, $expense, $je, $amount) {
                        $wip = ProjectWipEntry::create([
                            'project_id'       => $project->id,
                            'source_type'      => ProjectExpense::class,
                            'source_id'        => $expense->id,
                            'cost_type'        => $expense->category->wipCostType(),
                            'amount'           => $amount,
                            'description'      => $expense->description,
                            'entry_date'       => $expense->expense_date,
                            'journal_entry_id' => $je->id,
                            'created_by'       => auth()->id() ?? 1,
                        ]);
                        $expense->updateQuietly(['project_wip_entry_id' => $wip->id]);
                    });
                }
            }
        }
    }

    private function printPlan(): void
    {
        $this->newLine();
        if (empty($this->plan)) {
            $this->info('Không có action nào cần thực hiện.');
            return;
        }

        $this->info(count($this->plan) . ' action' . (count($this->plan) > 1 ? 's' : '') . ':');
        foreach ($this->plan as $i => $item) {
            $this->line("  " . ($i + 1) . ". [{$item['action']}] {$item['expense']}");
            $this->line("     → {$item['detail']}");
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->comment('Chạy với --apply để áp dụng các thay đổi trên.');
        } else {
            $this->info('Đã áp dụng ' . count($this->plan) . ' repairs.');
        }
    }
}
