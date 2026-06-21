<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectExpense;
use App\Models\ProjectWipEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectExtraCostTransferService
{
    // Các tài khoản KHÔNG được phép là nguồn kết chuyển sang 154
    // (tiền mặt, ngân hàng, phải thu, ứng trước, kho, phải trả, lương, thuế, vay)
    private const DISALLOWED_SOURCE_PREFIXES = [
        '111', '112', '113', '114', '115',
        '121', '128', '131', '133', '138', '141',
        '151', '152', '153', '155', '156', '157',
        '311', '331', '332', '333', '334', '335', '336', '337', '338',
        '341', '342',
    ];

    public function __construct(private AccountingService $accounting) {}

    /**
     * Kết chuyển một phần hoặc toàn bộ chi phí PS sang TK 154.
     * Tạo JE: Nợ 154 / Có credit_account (= debit_account gốc của chi phí).
     * Tạo project_wip_entries.
     */
    public function transferTo154(ProjectExpense $expense, array $data): ProjectExtraCostTransfer
    {
        $this->assertCanTransfer($expense);

        $amount       = (int) round((float) ($data['amount'] ?? 0));
        $transferDate = Carbon::parse($data['transfer_date'] ?? now());
        $description  = $data['description'] ?? "Kết chuyển CP {$expense->description} sang TK 154";
        $debitAcct    = $data['debit_account'] ?? '154';  // mặc định '154', cho phép sub-account như 1541

        // Tài khoản Có = tài khoản Nợ gốc của chi phí
        $creditAcct = $this->resolveOriginalDebitAccount($expense);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Số tiền kết chuyển phải lớn hơn 0.');
        }

        $remaining = $this->getRemainingTransferableAmount($expense);
        if ($amount > $remaining) {
            throw new \InvalidArgumentException(
                "Số tiền kết chuyển ({$amount}) vượt quá số tiền còn lại ({$remaining})."
            );
        }

        // Kiểm tra TK Nợ 154
        if (!str_starts_with($debitAcct, '154')) {
            throw new \InvalidArgumentException('Tài khoản Nợ kết chuyển phải bắt đầu bằng 154.');
        }

        // Kiểm tra TK Có (credit_account) không phải tài khoản bị cấm
        $this->assertAllowedSourceAccount($creditAcct);

        return DB::transaction(function () use ($expense, $amount, $transferDate, $description, $debitAcct, $creditAcct) {
            // Tìm JE gốc của chi phí để lưu liên kết cho reverse tự động
            $originalJe = JournalEntry::where('reference_type', ProjectExpense::class)
                ->where('reference_id', $expense->id)
                ->where('status', 'posted')
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->first();

            // Tạo JE: Nợ [154] / Có [original_debit]
            $je = $this->accounting->post(
                $description,
                $transferDate,
                [
                    [
                        'account'     => $debitAcct,
                        'debit'       => $amount,
                        'credit'      => 0,
                        'description' => $description,
                        'project_id'  => $expense->project_id,
                    ],
                    [
                        'account'     => $creditAcct,
                        'debit'       => 0,
                        'credit'      => $amount,
                        'description' => $description,
                        'project_id'  => $expense->project_id,
                    ],
                ],
                ProjectExtraCostTransfer::class,
                null, // sẽ cập nhật sau khi có transfer ID
                false, // posted ngay
            );

            // Tạo WIP entry
            $wip = ProjectWipEntry::create([
                'project_id'       => $expense->project_id,
                'source_type'      => ProjectExtraCostTransfer::class,
                'source_id'        => 0, // cập nhật sau
                'cost_type'        => $expense->category->wipCostType(),
                'amount'           => $amount,
                'description'      => $description,
                'entry_date'       => $transferDate,
                'journal_entry_id' => $je->id,
                'created_by'       => auth()->id(),
            ]);

            // Tạo bản ghi transfer
            $transfer = ProjectExtraCostTransfer::create([
                'project_id'             => $expense->project_id,
                'project_expense_id'     => $expense->id,
                'transfer_date'          => $transferDate,
                'debit_account'          => $debitAcct,
                'credit_account'         => $creditAcct,
                'amount'                 => $amount,
                'description'            => $description,
                'status'                 => 'posted',
                'journal_entry_id'       => $je->id,
                'transfer_from_entry_id' => $originalJe?->id,
                'project_wip_entry_id'   => $wip->id,
                'created_by'             => auth()->id(),
            ]);

            // Cập nhật source_id trên WIP và reference_id trên JE
            $wip->update(['source_id' => $transfer->id]);
            $je->update(['reference_id' => $transfer->id]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($expense)
                ->withProperties(['transfer_id' => $transfer->id, 'amount' => $amount, 'je_code' => $je->code])
                ->log('Kết chuyển chi phí PS sang TK 154');

            return $transfer;
        });
    }

    /**
     * Hủy kết chuyển: tạo bút toán đảo, cập nhật WIP entry sang cancelled.
     */
    public function cancelTransfer(ProjectExtraCostTransfer $transfer, string $reason): void
    {
        if ($transfer->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể hủy kết chuyển đang ở trạng thái đã hạch toán.');
        }

        DB::transaction(function () use ($transfer, $reason) {
            // Bút toán đảo: Nợ credit_account / Có debit_account (= 154)
            $reverseDate = Carbon::today();
            $desc = "Hủy kết chuyển: {$transfer->description}";

            $reverseJe = $this->accounting->post(
                $desc,
                $reverseDate,
                [
                    [
                        'account'     => $transfer->credit_account,
                        'debit'       => $transfer->amount,
                        'credit'      => 0,
                        'description' => $desc,
                        'project_id'  => $transfer->project_id,
                    ],
                    [
                        'account'     => $transfer->debit_account,
                        'debit'       => 0,
                        'credit'      => $transfer->amount,
                        'description' => $desc,
                        'project_id'  => $transfer->project_id,
                    ],
                ],
                ProjectExtraCostTransfer::class,
                $transfer->id,
                false,
            );

            // Cancel WIP entry
            if ($transfer->project_wip_entry_id) {
                ProjectWipEntry::where('id', $transfer->project_wip_entry_id)->update([
                    'status'       => 'cancelled',
                    'cancel_reason'=> $reason,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                ]);
            }

            // Cập nhật transfer
            $transfer->update([
                'status'                    => 'cancelled',
                'reversal_journal_entry_id' => $reverseJe->id,
                'cancelled_by'              => auth()->id(),
                'cancelled_at'              => now(),
                'cancel_reason'             => $reason,
            ]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($transfer->expense)
                ->withProperties(['transfer_id' => $transfer->id, 'reversal_je' => $reverseJe->code])
                ->log('Hủy kết chuyển chi phí PS sang TK 154');
        });
    }

    /**
     * Số tiền còn được phép kết chuyển (= amount gốc - tổng đã kết chuyển posted).
     */
    public function getRemainingTransferableAmount(ProjectExpense $expense): int
    {
        $transferred = ProjectExtraCostTransfer::where('project_expense_id', $expense->id)
            ->where('status', 'posted')
            ->sum('amount');

        return max(0, (int) round((float) $expense->amount) - (int) $transferred);
    }

    /**
     * Preview kết chuyển nhiều chi phí cùng lúc.
     */
    public function previewBatch(Project $project, array $expenseIds, array $amounts = []): array
    {
        $expenses = ProjectExpense::whereIn('id', $expenseIds)
            ->where('project_id', $project->id)
            ->get()
            ->keyBy('id');

        $rows        = [];
        $totalAmount = 0;
        $creditGroups = [];

        foreach ($expenseIds as $expenseId) {
            $expense = $expenses->get($expenseId);
            if (!$expense) {
                $rows[] = ['expense_id' => $expenseId, 'error' => 'Không tìm thấy chi phí.'];
                continue;
            }

            $remaining = $this->getRemainingTransferableAmount($expense);
            if ($remaining <= 0) {
                $rows[] = [
                    'expense_id'  => $expenseId,
                    'description' => $expense->description,
                    'remaining'   => 0,
                    'amount'      => 0,
                    'error'       => 'Không còn số tiền nào để kết chuyển.',
                ];
                continue;
            }

            $requestedAmount = isset($amounts[$expenseId])
                ? (int) round((float) $amounts[$expenseId])
                : $remaining;
            $amount = max(0, min($requestedAmount, $remaining));

            if ($amount <= 0) {
                $rows[] = [
                    'expense_id'  => $expenseId,
                    'description' => $expense->description,
                    'remaining'   => $remaining,
                    'amount'      => 0,
                    'error'       => 'Số tiền kết chuyển phải lớn hơn 0.',
                ];
                continue;
            }

            $creditAcct = $this->resolveOriginalDebitAccount($expense);
            $error      = null;
            try {
                $this->assertAllowedSourceAccount($creditAcct);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }

            $rows[] = [
                'expense_id'     => $expenseId,
                'description'    => $expense->description,
                'credit_account' => $creditAcct,
                'amount'         => $amount,
                'remaining'      => $remaining,
                'error'          => $error,
            ];

            if ($error === null) {
                $totalAmount += $amount;
                $creditGroups[$creditAcct] = ($creditGroups[$creditAcct] ?? 0) + $amount;
            }
        }

        $jeLines = [];
        if ($totalAmount > 0) {
            $jeLines[] = ['account_code' => '154', 'debit' => $totalAmount, 'credit' => 0];
            foreach ($creditGroups as $acct => $amt) {
                $jeLines[] = ['account_code' => $acct, 'debit' => 0, 'credit' => $amt];
            }
        }

        return [
            'rows'          => $rows,
            'valid_count'   => count(array_filter($rows, fn ($r) => ($r['error'] ?? null) === null && ($r['amount'] ?? 0) > 0)),
            'total_amount'  => $totalAmount,
            'credit_groups' => $creditGroups,
            'je_preview'    => $jeLines,
        ];
    }

    /**
     * Kết chuyển nhiều chi phí cùng lúc — mỗi chi phí tạo một transfer record riêng.
     * Toàn bộ nằm trong một DB transaction để đảm bảo atomic.
     *
     * @param  array  $data  ['expense_ids', 'amounts'(optional map), 'transfer_date', 'description'(optional)]
     * @return ProjectExtraCostTransfer[]
     */
    public function transferBatch(Project $project, array $data): array
    {
        $expenseIds   = $data['expense_ids'];
        $amounts      = $data['amounts'] ?? [];
        $transferDate = Carbon::parse($data['transfer_date'] ?? now());
        $description  = $data['description'] ?? "Kết chuyển chi phí dự án {$project->code} sang TK 154";

        $expenses = ProjectExpense::whereIn('id', $expenseIds)
            ->where('project_id', $project->id)
            ->get()
            ->keyBy('id');

        if ($expenses->count() !== count(array_unique($expenseIds))) {
            throw new \InvalidArgumentException('Một hoặc nhiều chi phí không thuộc dự án này hoặc không tồn tại.');
        }

        $transfers = [];

        DB::transaction(function () use ($expenses, $expenseIds, $amounts, $transferDate, $description, &$transfers) {
            foreach ($expenseIds as $expenseId) {
                $expense   = $expenses->get($expenseId);
                if (!$expense) {
                    continue;
                }

                $remaining = $this->getRemainingTransferableAmount($expense);
                $amount    = isset($amounts[$expenseId])
                    ? (int) round((float) $amounts[$expenseId])
                    : $remaining;
                $amount    = min($amount, $remaining);

                if ($amount <= 0) {
                    continue;
                }

                // transferTo154 wraps in its own transaction (→ savepoint in PostgreSQL)
                $transfers[] = $this->transferTo154($expense, [
                    'transfer_date' => $transferDate->toDateString(),
                    'amount'        => $amount,
                    'debit_account' => '154',
                    'description'   => $description,
                ]);
            }
        });

        return $transfers;
    }

    /**
     * Preview bút toán kết chuyển (không lưu DB).
     */
    public function previewTransfer(ProjectExpense $expense, array $data): array
    {
        $amount     = (int) round((float) ($data['amount'] ?? $expense->amount));
        $debitAcct  = $data['debit_account'] ?? '154';
        $creditAcct = $this->resolveOriginalDebitAccount($expense);
        $remaining  = $this->getRemainingTransferableAmount($expense);

        return [
            'debit_account'   => $debitAcct,
            'credit_account'  => $creditAcct,
            'amount'          => $amount,
            'remaining'       => $remaining,
            'expense_amount'  => (int) round((float) $expense->amount),
            'transferred'     => (int) round((float) $expense->amount) - $remaining,
            'je_lines'        => [
                ['account_code' => $debitAcct,  'debit' => $amount, 'credit' => 0],
                ['account_code' => $creditAcct, 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    // ─── private helpers ────────────────────────────────────────────────────

    private function assertCanTransfer(ProjectExpense $expense): void
    {
        $debitAcct = $this->resolveOriginalDebitAccount($expense);

        if (str_starts_with($debitAcct, '154')) {
            throw new \RuntimeException('Chi phí này đã hạch toán trực tiếp vào TK 154, không cần kết chuyển.');
        }

        $this->assertAllowedSourceAccount($debitAcct);

        // Phải có JE gốc
        $hasJe = JournalEntry::where('reference_type', ProjectExpense::class)
            ->where('reference_id', $expense->id)
            ->whereIn('status', ['draft', 'posted'])
            ->exists();

        if (!$hasJe) {
            throw new \RuntimeException('Chi phí này chưa được hạch toán. Không thể kết chuyển.');
        }
    }

    private function assertAllowedSourceAccount(string $accountCode): void
    {
        foreach (self::DISALLOWED_SOURCE_PREFIXES as $prefix) {
            if (str_starts_with($accountCode, $prefix)) {
                throw new \InvalidArgumentException(
                    "Tài khoản {$accountCode} không được phép là tài khoản nguồn kết chuyển sang TK 154."
                );
            }
        }
    }

    private function resolveOriginalDebitAccount(ProjectExpense $expense): string
    {
        if ($expense->debit_account) {
            return $expense->debit_account;
        }

        // Fallback về category-based account (giống ProjectWipService::resolveDebitAccount)
        $settingKey = match ($expense->category) {
            \App\Enums\ExpenseCategory::Labor     => 'project_labor_account',
            \App\Enums\ExpenseCategory::Equipment => 'project_equipment_account',
            \App\Enums\ExpenseCategory::Material  => 'project_material_account',
            \App\Enums\ExpenseCategory::Transport => 'project_transport_account',
            \App\Enums\ExpenseCategory::Other     => 'project_other_account',
        };

        return AccountingSettings::get($settingKey, $expense->category->defaultDebitAccount());
    }
}
