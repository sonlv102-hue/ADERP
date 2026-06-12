<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectWipService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Tạo WIP entry từ phiếu xuất kho dự án.
     * Gọi sau khi journal Nợ 154 / Có 156 đã được post.
     */
    public function createFromStockExit(StockExit $exit, int $journalEntryId): void
    {
        if (!$exit->project_id) return;

        $exit->load('items.product');
        $totalAmount = 0;

        foreach ($exit->items as $item) {
            $vatRate     = (float) ($item->product?->vat_percent ?? 10);
            $costInclTax = (float) ($item->product?->cost_price ?? 0);
            $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
            $costExclTax = $costInclTax / $divisor;
            $totalAmount += (int) round($costExclTax * $item->quantity);
        }

        if ($totalAmount <= 0) return;

        ProjectWipEntry::create([
            'project_id'       => $exit->project_id,
            'source_type'      => StockExit::class,
            'source_id'        => $exit->id,
            'cost_type'        => 'material',
            'amount'           => $totalAmount,
            'description'      => "Xuất vật tư/hàng hóa - phiếu {$exit->code}",
            'entry_date'       => $exit->exit_date,
            'journal_entry_id' => $journalEntryId,
            'created_by'       => auth()->id(),
        ]);
    }

    /**
     * Ghi nhận chi phí phát sinh dự án vào WIP.
     * Nợ 154 / Có 627x (chi phí sản xuất chung tương ứng)
     */
    public function createFromExpense(ProjectExpense $expense): void
    {
        $amount = (int) round((float) $expense->amount);
        if ($amount <= 0 || !$expense->project_id) return;

        $creditAccount = match ($expense->category) {
            ExpenseCategory::Labor     => '6271', // Lương giám sát
            ExpenseCategory::Material  => '6272', // Vật tư phụ
            ExpenseCategory::Transport => '6278', // Chi phí vận chuyển
            ExpenseCategory::Other     => '6279', // Chi phí khác
        };

        DB::transaction(function () use ($expense, $amount, $creditAccount) {
            $projectCode = $expense->project?->code ?? $expense->project_id;
            $je = $this->accounting->post(
                "Chi phí phát sinh dự án {$projectCode}",
                Carbon::parse($expense->expense_date),
                [
                    ['account' => '154', 'debit' => $amount, 'credit' => 0,
                     'description' => $expense->description, 'project_id' => $expense->project_id],
                    ['account' => $creditAccount, 'debit' => 0, 'credit' => $amount,
                     'description' => $expense->description, 'project_id' => $expense->project_id],
                ],
                ProjectExpense::class, $expense->id, true
            );

            $wipType = match ($expense->category) {
                ExpenseCategory::Labor    => 'labor',
                ExpenseCategory::Material => 'material',
                default                   => 'overhead',
            };

            ProjectWipEntry::create([
                'project_id'       => $expense->project_id,
                'source_type'      => ProjectExpense::class,
                'source_id'        => $expense->id,
                'cost_type'        => $wipType,
                'amount'           => $amount,
                'description'      => $expense->description,
                'entry_date'       => $expense->expense_date,
                'journal_entry_id' => $je->id,
                'created_by'       => auth()->id(),
            ]);
        });
    }

    /**
     * Tổng hợp chi phí WIP theo loại chi phí cho một dự án.
     */
    public function getWipSummary(int $projectId): array
    {
        $rows = ProjectWipEntry::where('project_id', $projectId)
            ->selectRaw('cost_type, SUM(amount) as total')
            ->groupBy('cost_type')
            ->get()
            ->keyBy('cost_type');

        $labels = ProjectWipEntry::$costTypeLabels;
        $result = [];
        foreach ($labels as $type => $label) {
            $result[] = [
                'cost_type' => $type,
                'label'     => $label,
                'total'     => (int) ($rows[$type]?->total ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Lấy danh sách WIP entries của một dự án (cho bảng chi tiết).
     */
    public function getWipEntries(int $projectId): \Illuminate\Support\Collection
    {
        return ProjectWipEntry::where('project_id', $projectId)
            ->with('journalEntry')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProjectWipEntry $e) => [
                'id'          => $e->id,
                'cost_type'   => $e->cost_type,
                'label'       => $e->costTypeLabel(),
                'amount'      => $e->amount,
                'description' => $e->description,
                'entry_date'  => $e->entry_date->format('d/m/Y'),
                'journal_code' => $e->journalEntry?->code,
                'source_type' => class_basename($e->source_type),
                'source_id'   => $e->source_id,
            ]);
    }

    /**
     * Kết chuyển chi phí dở dang vào giá vốn khi dự án hoàn thành/nghiệm thu.
     * Nợ 632 / Có 154 = tổng WIP
     */
    public function recognizeCost(Project $project, ?string $notes = null): void
    {
        $total = (int) ProjectWipEntry::where('project_id', $project->id)->sum('amount');
        if ($total <= 0) {
            throw new \RuntimeException('Dự án chưa có chi phí dở dang nào để kết chuyển.');
        }

        DB::transaction(function () use ($project, $total, $notes) {
            $this->accounting->post(
                "Kết chuyển giá thành dự án {$project->code}",
                Carbon::today(),
                [
                    ['account' => '6322', 'debit' => $total, 'credit' => 0,
                     'description' => "Giá vốn công trình {$project->name}", 'project_id' => $project->id],
                    ['account' => '154', 'debit' => 0, 'credit' => $total,
                     'description' => "Kết chuyển CP dở dang {$project->name}", 'project_id' => $project->id],
                ],
                'project', $project->id, true, $notes
            );
        });
    }
}
