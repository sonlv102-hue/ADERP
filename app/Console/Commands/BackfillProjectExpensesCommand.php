<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\Supplier;
use App\Services\ProjectWipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill JE cho các chi phí phát sinh dự án chưa có bút toán.
 *
 * Usage:
 *   php artisan projects:backfill-expenses --project=DA-0001 [--dry-run]
 *   php artisan projects:backfill-expenses --project=DA-0001 --category=equipment --debit=6237
 */
class BackfillProjectExpensesCommand extends Command
{
    protected $signature = 'projects:backfill-expenses
        {--project= : Mã dự án (ví dụ: DA-0001)}
        {--supplier= : Tên hoặc mã NCC để gán (tìm kiếm gần đúng)}
        {--category=equipment : Phân loại chi phí sau backfill (mặc định: equipment)}
        {--debit=6237 : TK Nợ sau backfill (mặc định: 6237)}
        {--dry-run : Chỉ preview, không lưu}';

    protected $description = 'Backfill bút toán cho chi phí phát sinh dự án chưa có JE (idempotent)';

    public function __construct(private ProjectWipService $wip)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $code    = $this->option('project');
        $isDry   = $this->option('dry-run');
        $catVal  = $this->option('category');
        $debitTk = $this->option('debit');
        $supplierQuery = $this->option('supplier');

        $projects = $code
            ? Project::where('code', $code)->get()
            : Project::all();

        if ($projects->isEmpty()) {
            $this->error("Không tìm thấy dự án: {$code}");
            return 1;
        }

        // Tìm NCC nếu có --supplier
        $supplier = null;
        if ($supplierQuery) {
            $supplier = Supplier::where('name', 'like', "%{$supplierQuery}%")
                ->orWhere('code', $supplierQuery)
                ->first();
            if (!$supplier) {
                $this->warn("Không tìm thấy NCC '{$supplierQuery}' — bỏ qua gán supplier_id.");
            } else {
                $this->line("NCC: {$supplier->code} — {$supplier->name}");
            }
        }

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($projects as $project) {
            $this->info("\nDự án: {$project->code} — {$project->name}");

            $expenses = $project->expenses()->with('supplier')->get();

            foreach ($expenses as $expense) {
                $hasJe = JournalEntry::where('reference_type', ProjectExpense::class)
                    ->where('reference_id', $expense->id)
                    ->exists();

                if ($hasJe) {
                    $this->line("  [SKIP] #{$expense->id} — đã có JE: " . substr($expense->description, 0, 50));
                    $totalSkipped++;
                    continue;
                }

                if ((float) $expense->amount <= 0) {
                    $this->line("  [SKIP] #{$expense->id} — amount = 0");
                    $totalSkipped++;
                    continue;
                }

                $this->line(sprintf(
                    "  [%s] #%d | %s | %s | %s",
                    $isDry ? 'DRY' : 'CREATE',
                    $expense->id,
                    number_format($expense->amount),
                    $catVal,
                    substr($expense->description, 0, 45)
                ));

                if (!$isDry) {
                    DB::transaction(function () use ($expense, $supplier, $catVal, $debitTk) {
                        $expense->update([
                            'category'     => $catVal,
                            'supplier_id'  => $supplier?->id ?? $expense->supplier_id,
                            'debit_account'=> $debitTk,
                            'credit_account' => $expense->credit_account ?? '3311',
                            'payment_method' => $expense->payment_method ?? 'payable',
                        ]);
                        $expense->refresh();
                        $expense->loadMissing('project', 'supplier');
                        $this->wip->createFromExpense($expense);
                    });
                }

                $totalCreated++;
            }
        }

        $this->newLine();
        $this->info("Hoàn thành: {$totalCreated} JE tạo mới, {$totalSkipped} bỏ qua.");
        if ($isDry) {
            $this->warn('Chế độ DRY RUN — chưa lưu gì cả. Thêm --apply hoặc bỏ --dry-run để thực thi.');
        }

        return 0;
    }
}
