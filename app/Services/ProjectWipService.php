<?php

namespace App\Services;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherType;
use App\Enums\ExpenseCategory;
use App\Models\CashVoucher;
use App\Models\CashVoucherLine;
use App\Models\JournalEntry;
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
    public function __construct(
        private AccountingService $accounting,
        private CashVoucherService $cashVoucherSvc,
    ) {}

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
     * Tạo WIP entry từ dòng của PurchaseInvoice (ví dụ: chi phí hạch toán trực tiếp TK154).
     */
    public function createFromPurchaseInvoiceItem(\App\Models\PurchaseInvoice $invoice, \App\Models\PurchaseInvoiceItem $item, int $journalEntryId): void
    {
        $projectId = $item->project_id ?? $invoice->project_id;
        if (!$projectId) return;

        $amount = (int) round((float) $item->amount);
        if ($amount <= 0) return;

        ProjectWipEntry::create([
            'project_id'       => $projectId,
            'source_type'      => \App\Models\PurchaseInvoice::class,
            'source_id'        => $invoice->id,
            'source_item_id'   => $item->id,
            'cost_type'        => 'overhead',
            'amount'           => $amount,
            'vat_amount'       => $item->tax_amount ?? 0,
            'description'      => $item->description ?: "Hóa đơn mua {$invoice->code}",
            'entry_date'       => $invoice->invoice_date ?? now(),
            'journal_entry_id' => $journalEntryId,
            'created_by'       => auth()->id(),
        ]);
    }

    /**
     * Ghi nhận chi phí phát sinh dự án.
     *
     * @param bool $useCashVoucher  Khi true (chỉ expenseBatchStore), cash payment tạo CashVoucher (PC-)
     *                              làm nguồn JE duy nhất, chống double-post và cập nhật số dư quỹ.
     *                              Khi false (addExpense legacy), tạo JE trực tiếp như cũ.
     */
    public function createFromExpense(ProjectExpense $expense, bool $useCashVoucher = false): void
    {
        $amount = (int) round((float) $expense->amount);
        if ($amount <= 0 || !$expense->project_id) return;

        $vatAmount   = (int) ($expense->vat_amount ?? 0);
        $debitTk     = $expense->debit_account ?? $this->resolveDebitAccount($expense);
        $method      = $expense->payment_method ?? 'payable';
        $projectCode = $expense->project?->code ?? $expense->project_id;

        if (preg_match('/^15[26]/', $debitTk)) {
            throw new \InvalidArgumentException(
                "TK {$debitTk} (vật tư/hàng hóa) không được dùng trong Chi phí PS. Sử dụng phiếu xuất kho."
            );
        }

        $isDirectTo154 = str_starts_with($debitTk, '154');

        DB::transaction(function () use ($expense, $amount, $vatAmount, $debitTk, $method, $projectCode, $isDirectTo154, $useCashVoucher) {
            $cashVoucherId = null;

            if ($useCashVoucher && $method === 'cash' && $expense->fund_id) {
                // Tạo CashVoucher (PC-) → CashVoucherService::confirm() tạo JE duy nhất.
                // Cập nhật số dư quỹ, tránh double-post.
                [$je, $cashVoucherId] = $this->createExpenseCashVoucher($expense, $debitTk, $amount, $vatAmount);
            } else {
                // Legacy path: tạo JE trực tiếp (addExpense cũ, hoặc non-cash, hoặc cash không có fund_id)
                $creditLines = $this->buildCreditLines($expense, $amount, $vatAmount);
                $lines = [
                    ['account' => $debitTk, 'debit' => $amount, 'credit' => 0,
                     'description' => $expense->description, 'project_id' => $expense->project_id],
                ];
                if ($vatAmount > 0) {
                    $lines[] = ['account' => '1331', 'debit' => $vatAmount, 'credit' => 0,
                        'description' => 'Thuế GTGT — ' . $expense->description,
                        'project_id'  => $expense->project_id];
                }
                foreach ($creditLines as $cl) {
                    $lines[] = $cl;
                }
                $je = $this->accounting->post(
                    "Chi phí phát sinh dự án {$projectCode}",
                    Carbon::parse($expense->expense_date),
                    $lines,
                    ProjectExpense::class, $expense->id, true
                );
            }

            $wipId = null;
            if ($isDirectTo154) {
                $wip = ProjectWipEntry::create([
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
                $wipId = $wip->id;
            }

            $expense->updateQuietly([
                'journal_entry_id'     => $je->id,
                'project_wip_entry_id' => $wipId,
                'cash_voucher_id'      => $cashVoucherId,
                'status'               => 'posted',
            ]);
        });
    }

    /**
     * Tạo CashVoucher (PC-) cho khoản chi phí PS bằng tiền mặt.
     * Các dòng bút toán được tạo thủ công để hỗ trợ PIT split và VAT.
     * CashVoucherService::confirm() tạo JE duy nhất — không tạo thêm JE trong ProjectExpense.
     *
     * @return array{0: JournalEntry, 1: int}  [je, cash_voucher_id]
     */
    private function createExpenseCashVoucher(
        ProjectExpense $expense,
        string $debitTk,
        int $amount,
        int $vatAmount
    ): array {
        $expense->loadMissing('fund');
        $fundAccount = $expense->fund?->account_code
            ?? AccountingSettings::get('cash_account', '1111');

        $pitEnabled = (bool) ($expense->pit_withholding_enabled ?? false);
        $pitAmount  = (int) ($expense->pit_amount ?? 0);

        // Số tiền thực chi (tiền mặt ra khỏi quỹ)
        $cashAmount = $pitEnabled && $pitAmount > 0
            ? max(0, $amount - $pitAmount)   // không bao gồm tiền khấu trừ
            : ($amount + $vatAmount);         // bao gồm VAT

        $voucher = CashVoucher::create([
            'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
            'type'           => CashVoucherType::Payment->value,
            'status'         => 'draft',
            'fund_id'        => $expense->fund_id,
            'amount'         => $cashAmount,
            'voucher_date'   => $expense->expense_date,
            'description'    => "Chi CPPS dự án: {$expense->description}",
            'reference_type' => 'project_expense',
            'reference_id'   => $expense->id,
            'business_type'  => CashVoucherBusinessType::ProjectExpensePayment->value,
            'created_by'     => auth()->id(),
        ]);

        // Tạo CashVoucherLines thủ công — mỗi line là 1 cặp Dr/Cr
        $lines = [];
        if ($pitEnabled && $pitAmount > 0) {
            // PIT split: Nợ debit_tk (thực trả) / Có quỹ + Nợ debit_tk (PIT) / Có 3335
            $netAmount = max(0, $amount - $pitAmount);
            $lines[] = ['debit_account' => $debitTk, 'credit_account' => $fundAccount,
                        'amount' => $netAmount,
                        'description' => $expense->description . ' (thực trả)'];
            $lines[] = ['debit_account' => $debitTk, 'credit_account' => '3335',
                        'amount' => $pitAmount,
                        'description' => 'Thuế TNCN khấu trừ — ' . $expense->description];
        } else {
            $lines[] = ['debit_account' => $debitTk, 'credit_account' => $fundAccount,
                        'amount' => $amount,
                        'description' => $expense->description];
            if ($vatAmount > 0) {
                $lines[] = ['debit_account' => '1331', 'credit_account' => $fundAccount,
                            'amount' => $vatAmount,
                            'description' => 'Thuế GTGT — ' . $expense->description];
            }
        }

        foreach ($lines as $i => $line) {
            CashVoucherLine::create(array_merge($line, [
                'cash_voucher_id' => $voucher->id,
                'sort_order'      => $i,
            ]));
        }

        // confirm() validates lines và tạo JE (reference_type='cash_voucher')
        $this->cashVoucherSvc->confirm($voucher);

        $je = JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $voucher->id)
            ->where('status', 'posted')
            ->firstOrFail();

        return [$je, $voucher->id];
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
        // credit_account được set trực tiếp từ form → ưu tiên tuyệt đối
        if ($expense->credit_account) {
            return $expense->credit_account;
        }

        $method = $expense->payment_method ?? 'payable';

        if ($method === 'cash') {
            // Nếu có fund_id thì lấy TK quỹ; fallback về 1111
            if ($expense->fund_id) {
                $expense->loadMissing('fund');
                return $expense->fund?->account_code ?? AccountingSettings::get('cash_account', '1111');
            }
            return AccountingSettings::get('cash_account', '1111');
        }
        if ($method === 'bank') {
            if ($expense->bank_account_id) {
                $expense->loadMissing('bankAccount');
                return $expense->bankAccount?->account_code ?? AccountingSettings::get('bank_account', '1121');
            }
            return AccountingSettings::get('bank_account', '1121');
        }

        if ($method === 'advance') {
            return '141';
        }
        if ($method === 'salary') {
            return AccountingSettings::get('salary_payable_account', '3341');
        }
        if ($method === 'misc') {
            return '3388';
        }
        if ($method === 'depreciation') {
            return '214';
        }
        if ($method === 'insurance') {
            // TK 338 chi tiết được lưu trong credit_account; fallback BHXH NSDLĐ
            return $expense->credit_account ?? '33831';
        }

        // payable: dùng TK phải trả của NCC nếu có
        if ($expense->supplier_id) {
            $expense->loadMissing('supplier');
            return $expense->supplier?->payable_account_code ?? '3311';
        }

        return '3311';
    }

    /**
     * Xây dựng danh sách dòng Có cho JE chi phí PS.
     * Xử lý 2 case đặc biệt:
     *   - PIT split: freelance_contractor + cash/bank + pit_enabled → Có TK tiền + Có 3335
     *   - Trường hợp còn lại: 1 dòng Có duy nhất (bao gồm VAT nếu có)
     */
    private function buildCreditLines(ProjectExpense $expense, int $amount, int $vatAmount): array
    {
        $creditTk  = $expense->credit_account ?? $this->resolveCreditAccount($expense);
        $pitEnabled = (bool) ($expense->pit_withholding_enabled ?? false);
        $pitAmount  = (int) ($expense->pit_amount ?? 0);
        $method     = $expense->payment_method ?? 'payable';

        if ($pitEnabled && $pitAmount > 0 && in_array($method, ['cash', 'bank'])) {
            $netAmount = $amount - $pitAmount;
            return [
                ['account' => $creditTk, 'debit' => 0, 'credit' => $netAmount,
                 'description' => $expense->description . ' (thực trả)', 'project_id' => null],
                ['account' => '3335',    'debit' => 0, 'credit' => $pitAmount,
                 'description' => 'Thuế TNCN khấu trừ — ' . $expense->description, 'project_id' => null],
            ];
        }

        return [
            ['account' => $creditTk, 'debit' => 0, 'credit' => $amount + $vatAmount,
             'description' => $expense->description, 'project_id' => null],
        ];
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
