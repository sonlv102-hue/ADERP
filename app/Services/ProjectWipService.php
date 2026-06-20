<?php

namespace App\Services;

use App\Enums\ExpenseCategory;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Services\AccountingSettings;
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
     * Tạo WIP entry cho từng dòng xuất kho (per-item traceability).
     * Ghi product_id, quantity, unit_cost, stock_exit_item_id để truy vết vật tư.
     */
    public function createFromStockExitItem(StockExit $exit, StockExitItem $item, int $journalEntryId): void
    {
        if (!$exit->project_id) return;

        // Ưu tiên FIFO cost, fallback về product cost
        if ($item->total_cost !== null && (float)$item->total_cost > 0) {
            $amount   = (int) round((float)$item->total_cost);
            $unitCost = (float)($item->source_cost ?? 0);
        } else {
            $vatRate     = (float) ($item->product?->vat_percent ?? 10);
            $costInclTax = (float) ($item->product?->cost_price ?? 0);
            $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
            $unitCost    = $costInclTax / $divisor;
            $amount      = (int) round($unitCost * (float)$item->quantity);
        }

        if ($amount <= 0) return;

        ProjectWipEntry::create([
            'project_id'        => $exit->project_id,
            'source_type'       => StockExit::class,
            'source_id'         => $exit->id,
            'cost_type'         => 'material',
            'amount'            => $amount,
            'description'       => "Xuất vật tư {$item->product?->name} - phiếu {$exit->code}",
            'entry_date'        => $exit->exit_date,
            'journal_entry_id'  => $journalEntryId,
            'created_by'        => auth()->id(),
            'product_id'        => $item->product_id,
            'quantity'          => $item->quantity,
            'unit_cost'         => $unitCost,
            'stock_exit_item_id' => $item->id,
        ]);
    }

    /**
     * Ghi nhận chi phí phát sinh dự án theo Thông tư 133.
     * Nợ [expense TK theo category] + Nợ 1331 (nếu có VAT) / Có [payment TK].
     * Debit/credit TK có thể override qua expense.debit_account / credit_account.
     */
    public function createFromExpense(ProjectExpense $expense): void
    {
        $amount = (int) round((float) $expense->amount);
        if ($amount <= 0 || !$expense->project_id) return;

        $vatAmount   = (int) ($expense->vat_amount ?? 0);
        $debitTk     = $expense->debit_account  ?? $this->resolveDebitAccount($expense);
        $creditTk    = $expense->credit_account ?? $this->resolveCreditAccount($expense);
        $projectCode = $expense->project?->code ?? $expense->project_id;

        DB::transaction(function () use ($expense, $amount, $vatAmount, $debitTk, $creditTk, $projectCode) {
            $lines = [
                ['account' => $debitTk, 'debit' => $amount, 'credit' => 0,
                 'description' => $expense->description, 'project_id' => $expense->project_id],
            ];

            if ($vatAmount > 0) {
                $lines[] = ['account' => '1331', 'debit' => $vatAmount, 'credit' => 0,
                    'description' => 'Thuế GTGT — ' . $expense->description,
                    'project_id'  => $expense->project_id];
            }

            $lines[] = ['account' => $creditTk, 'debit' => 0, 'credit' => $amount + $vatAmount,
                'description' => $expense->description, 'project_id' => $expense->project_id];

            $je = $this->accounting->post(
                "Chi phí phát sinh dự án {$projectCode}",
                Carbon::parse($expense->expense_date),
                $lines,
                ProjectExpense::class, $expense->id, true
            );

            ProjectWipEntry::create([
                'project_id'       => $expense->project_id,
                'source_type'      => ProjectExpense::class,
                'source_id'        => $expense->id,
                'cost_type'        => $expense->category->wipCostType(),
                'amount'           => $amount,
                'description'      => $expense->description,
                'entry_date'       => $expense->expense_date,
                'journal_entry_id' => $je->id,
                'created_by'       => auth()->id(),
            ]);
        });
    }

    private function resolveDebitAccount(ProjectExpense $expense): string
    {
        $settingKey = match ($expense->category) {
            ExpenseCategory::Labor     => 'project_labor_account',
            ExpenseCategory::Equipment => 'project_equipment_account',
            ExpenseCategory::Material  => 'project_material_account',
            ExpenseCategory::Transport => 'project_transport_account',
            ExpenseCategory::Other     => 'project_other_account',
        };

        return AccountingSettings::get($settingKey, $expense->category->defaultDebitAccount());
    }

    private function resolveCreditAccount(ProjectExpense $expense): string
    {
        $method = $expense->payment_method ?? 'payable';

        if ($method === 'cash') {
            return AccountingSettings::get('cash_account', '1111');
        }
        if ($method === 'bank') {
            return AccountingSettings::get('bank_account', '1121');
        }

        // payable: dùng TK phải trả của NCC nếu có
        if ($expense->supplier_id && $expense->relationLoaded('supplier') && $expense->supplier) {
            return $expense->supplier->payable_account_code ?? '3311';
        }
        if ($expense->supplier_id) {
            $expense->loadMissing('supplier');
            return $expense->supplier?->payable_account_code ?? '3311';
        }

        return '3311';
    }

    /**
     * Tổng hợp chi phí WIP theo loại chi phí cho một dự án (chỉ active).
     */
    public function getWipSummary(int $projectId): array
    {
        $rows = ProjectWipEntry::where('project_id', $projectId)
            ->where('status', 'active')
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
     * Lấy danh sách WIP entries của một dự án (cho bảng chi tiết, bao gồm cả không active).
     */
    public function getWipEntries(int $projectId): \Illuminate\Support\Collection
    {
        return ProjectWipEntry::where('project_id', $projectId)
            ->with(['journalEntry', 'source', 'cancelledByUser'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProjectWipEntry $e) => [
                'id'               => $e->id,
                'cost_type'        => $e->cost_type,
                'label'            => $e->costTypeLabel(),
                'amount'           => $e->amount,
                'description'      => $e->description,
                'entry_date'       => $e->entry_date->format('d/m/Y'),
                'journal_code'     => $e->journalEntry?->code,
                'journal_entry_id' => $e->journal_entry_id,
                'has_je'           => !is_null($e->journal_entry_id),
                'source_type'      => $e->source_type,
                'source_type_short'=> class_basename($e->source_type ?? ''),
                'source_id'        => $e->source_id,
                'source_code'      => ($e->source_type === \App\Models\StockExit::class) ? ($e->source?->code ?? null) : null,
                'status'           => $e->status ?? 'active',
                'status_label'     => $e->statusLabel(),
                'status_color'     => $e->statusColor(),
                'cancel_reason'    => $e->cancel_reason,
                'cancelled_at'     => $e->cancelled_at?->format('d/m/Y H:i'),
                'cancelled_by_name'=> $e->cancelledByUser?->name,
                'is_stock_exit'    => ($e->source_type === \App\Models\StockExit::class),
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
                    ['account' => AccountingSettings::get('default_cogs_account', '632'), 'debit' => $total, 'credit' => 0,
                     'description' => "Giá vốn công trình {$project->name}", 'project_id' => $project->id],
                    ['account' => AccountingSettings::get('project_wip_account', '154'), 'debit' => 0, 'credit' => $total,
                     'description' => "Kết chuyển CP dở dang {$project->name}", 'project_id' => $project->id],
                ],
                'project', $project->id, true, $notes
            );
        });
    }
}
