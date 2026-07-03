<?php

namespace App\Services;

use App\Models\AccountCode;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolDisposal;
use App\Models\SmallToolReceipt;
use Carbon\Carbon;

/**
 * Bút toán kế toán CCDC — TT133.
 * KHÔNG dùng TK 214. Luồng: 1531 → 2422 → 6422/6421/154
 */
class SmallToolJournalService
{
    public function __construct(protected AccountingService $accounting) {}

    // --------------------------------------------------
    // 1. Nhập kho CCDC: Nợ 1531 / Nợ 1331 / Có 331/1111/1121
    // --------------------------------------------------

    public function createReceiptJournal(SmallToolReceipt $receipt): \App\Models\JournalEntry
    {
        $totalCost  = (float) $receipt->total_cost;
        $vatAmount  = (float) $receipt->vat_amount;
        $totalPay   = (float) $receipt->total_amount;
        $creditCode = $this->resolveCreditAccount($receipt->payment_type, $receipt->fund);
        $date       = Carbon::parse($receipt->receipt_date);

        $lines = [];
        foreach ($receipt->items as $item) {
            $lines[] = [
                'account'     => $item->tool->stock_account_code ?: '1531',
                'debit'       => (float) $item->total_amount - (float) $item->vat_amount,
                'credit'      => 0,
                'description' => "Nhập kho CCDC: {$item->tool->name}",
            ];
        }

        if ($vatAmount > 0) {
            $this->assertDetail('1331');
            $lines[] = [
                'account'     => '1331',
                'debit'       => $vatAmount,
                'credit'      => 0,
                'description' => 'VAT mua CCDC',
            ];
        }

        $this->assertDetail($creditCode);
        $lines[] = [
            'account'     => $creditCode,
            'debit'       => 0,
            'credit'      => $totalPay,
            'description' => 'Thanh toán/công nợ mua CCDC: ' . $receipt->code,
        ];

        return $this->accounting->post(
            description: "Nhập kho CCDC: {$receipt->code}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool_receipt',
            referenceId: $receipt->id,
            isAuto: false,
            journalSourceType: 'small_tool_receipt',
        );
    }

    // --------------------------------------------------
    // 2. Mua CCDC dùng ngay, không qua kho
    // --------------------------------------------------

    public function createDirectUseJournal(SmallTool $tool): \App\Models\JournalEntry
    {
        $originalCost = (float) $tool->original_cost;
        $vatAmount    = (float) $tool->vat_amount;
        $totalPay     = (float) $tool->total_cost;
        $creditCode   = $this->resolveCreditAccount($tool->payment_type, $tool->fund);
        $date         = Carbon::parse($tool->in_service_date ?? $tool->purchase_date ?? now());

        $debitCode = $tool->recognition_method === 'allocation'
            ? ($tool->pending_account_code ?: '2422')
            : ($tool->expense_account_code ?: '6422');

        $this->assertDetail($debitCode);
        $this->assertDetail($creditCode);

        $lines = [
            [
                'account'     => $debitCode,
                'debit'       => $originalCost,
                'credit'      => 0,
                'description' => ($tool->recognition_method === 'allocation')
                    ? "CCDC chờ phân bổ: {$tool->name}"
                    : "Chi phí CCDC dùng ngay: {$tool->name}",
            ],
        ];

        if ($vatAmount > 0) {
            $this->assertDetail('1331');
            $lines[] = [
                'account'     => '1331',
                'debit'       => $vatAmount,
                'credit'      => 0,
                'description' => "VAT mua CCDC: {$tool->name}",
            ];
        }

        $lines[] = [
            'account'     => $creditCode,
            'debit'       => 0,
            'credit'      => $totalPay,
            'description' => "Thanh toán/công nợ mua CCDC: {$tool->code}",
        ];

        return $this->accounting->post(
            description: "Mua CCDC dùng ngay: {$tool->code} - {$tool->name}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool',
            referenceId: $tool->id,
            isAuto: false,
            journalSourceType: 'small_tool_direct',
        );
    }

    // --------------------------------------------------
    // 3. Xuất dùng CCDC từ kho (Nợ 6422/2422 / Có 1531)
    // --------------------------------------------------

    public function createIssueJournal(SmallTool $tool, float $amount, string $method, string $expenseAccount, ?int $projectId = null): \App\Models\JournalEntry
    {
        $stockAccount   = $tool->stock_account_code ?: '1531';
        $pendingAccount = $tool->pending_account_code ?: '2422';
        $debitCode      = $method === 'allocation' ? $pendingAccount : $expenseAccount;
        $date           = Carbon::parse($tool->in_service_date ?? now());

        $this->assertDetail($debitCode);
        $this->assertDetail($stockAccount);

        $lines = [
            [
                'account'     => $debitCode,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => ($method === 'allocation')
                    ? "CCDC chờ phân bổ: {$tool->name}"
                    : "Chi phí CCDC xuất dùng: {$tool->name}",
                'project_id'  => $projectId,
            ],
            [
                'account'     => $stockAccount,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "Xuất dùng CCDC: {$tool->name}",
            ],
        ];

        return $this->accounting->post(
            description: "Xuất dùng CCDC: {$tool->code} - {$tool->name}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool',
            referenceId: $tool->id,
            isAuto: false,
            journalSourceType: 'small_tool_issue',
        );
    }

    // --------------------------------------------------
    // 4. Phân bổ chi phí CCDC hàng tháng (Nợ 6422 / Có 2422)
    // --------------------------------------------------

    public function createAllocationJournal(SmallToolAllocation $alloc, ?int $projectId = null): \App\Models\JournalEntry
    {
        $tool        = $alloc->tool;
        $debitCode   = $alloc->debit_account ?: ($tool->expense_account_code ?: '6422');
        $creditCode  = $alloc->credit_account ?: ($tool->pending_account_code ?: '2422');
        $date        = Carbon::parse($alloc->period . '-01')->endOfMonth();

        $this->assertDetail($debitCode);
        $this->assertDetail($creditCode);

        $lines = [
            [
                'account'     => $debitCode,
                'debit'       => (float) $alloc->amount,
                'credit'      => 0,
                'description' => "Phân bổ CCDC {$alloc->period}: {$tool->name}",
                'project_id'  => $projectId,
            ],
            [
                'account'     => $creditCode,
                'debit'       => 0,
                'credit'      => (float) $alloc->amount,
                'description' => "Kết chuyển phân bổ CCDC: {$tool->name}",
            ],
        ];

        return $this->accounting->post(
            description: "Phân bổ CCDC kỳ {$alloc->period}: {$tool->code} - {$tool->name}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool_allocation',
            referenceId: $alloc->id,
            isAuto: false,
            journalSourceType: 'small_tool_allocation',
        );
    }

    // --------------------------------------------------
    // 4b. Số dư đầu kỳ CCDC — ghi nhận giá trị còn lại (không phải nguyên giá), đối ứng 4111
    // --------------------------------------------------

    public function createOpeningBalanceJournal(SmallTool $tool): \App\Models\JournalEntry
    {
        $remaining = (float) $tool->totalRemaining;
        $account   = $tool->pending_account_code ?: '2422';
        $date      = Carbon::createFromFormat('Y-m', $tool->opening_balance_period)->startOfMonth()->subDay();

        $this->assertDetail($account);

        $lines = [
            [
                'account'     => $account,
                'debit'       => $remaining,
                'credit'      => 0,
                'description' => "Số dư đầu kỳ CCDC: {$tool->name}",
            ],
            [
                'account'     => '4111',
                'debit'       => 0,
                'credit'      => $remaining,
                'description' => "Số dư đầu kỳ CCDC {$tool->opening_balance_period}",
            ],
        ];

        return $this->accounting->post(
            description: "Số dư đầu kỳ CCDC: {$tool->code} - {$tool->name}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool_opening_balance',
            referenceId: $tool->id,
            isAuto: false,
            journalSourceType: 'small_tool_opening_balance',
            excludeFromPeriodMovement: true,
            fiscalPeriod: $tool->opening_balance_period,
        );
    }

    // --------------------------------------------------
    // 5. Bút toán batch phân bổ nhiều CCDC trong 1 kỳ
    // --------------------------------------------------

    public function createBatchAllocationJournal(array $items, string $period): \App\Models\JournalEntry
    {
        $lines = [];
        $total = 0;

        foreach ($items as ['alloc' => $alloc, 'project_id' => $projectId]) {
            $tool      = $alloc->tool;
            $debitCode = $alloc->debit_account ?: ($tool->expense_account_code ?: '6422');
            $creditCode = $alloc->credit_account ?: ($tool->pending_account_code ?: '2422');
            $amount    = (float) $alloc->amount;

            $lines[] = [
                'account'     => $debitCode,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => "Phân bổ CCDC {$period}: {$tool->name}",
                'project_id'  => $projectId,
            ];
            $lines[] = [
                'account'     => $creditCode,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "CCDC chờ PB → kỳ {$period}",
            ];
            $total += $amount;
        }

        $date = Carbon::parse($period . '-01')->endOfMonth();

        return $this->accounting->post(
            description: "Phân bổ CCDC kỳ {$period}",
            date: $date,
            lines: $lines,
            referenceType: 'small_tool_allocation_batch',
            isAuto: false,
            journalSourceType: 'small_tool_allocation',
            notes: "Tổng {$total} VND — " . count($items) . " CCDC",
        );
    }

    // --------------------------------------------------
    // 6. Bút toán hỏng/mất/thanh lý
    // --------------------------------------------------

    public function createDisposalJournals(SmallToolDisposal $disposal): array
    {
        $tool       = $disposal->tool;
        $date       = Carbon::parse($disposal->disposal_date);
        $netValue   = (float) $disposal->net_value_snapshot;
        $expCode    = $disposal->expense_account_code ?: '6422';
        $jeIds      = [];

        // Nếu còn giá trị chưa phân bổ → ghi nhận vào chi phí/tổn thất
        if ($netValue > 0) {
            $isAllocating = in_array($tool->status->value, ['allocating', 'fully_allocated']);
            $creditCode   = $isAllocating
                ? ($tool->pending_account_code ?: '2422')
                : ($tool->stock_account_code ?: '1531');

            $this->assertDetail($expCode);
            $this->assertDetail($creditCode);

            $writeoffLines = [
                [
                    'account'     => $expCode,
                    'debit'       => $netValue,
                    'credit'      => 0,
                    'description' => "{$disposal->disposalTypeLabel()} CCDC: {$tool->name}",
                ],
                [
                    'account'     => $creditCode,
                    'debit'       => 0,
                    'credit'      => $netValue,
                    'description' => "Xóa sổ CCDC: {$tool->name}",
                ],
            ];

            $je1    = $this->accounting->post(
                description: "{$disposal->disposalTypeLabel()} CCDC: {$tool->code} - {$tool->name}",
                date: $date,
                lines: $writeoffLines,
                referenceType: 'small_tool_disposal',
                referenceId: $disposal->id,
                isAuto: false,
                journalSourceType: 'small_tool_disposal',
            );
            $jeIds[] = $je1->id;
        }

        // Doanh thu thanh lý nếu có
        if ($disposal->disposal_type === 'liquidated' && (float) $disposal->recovery_amount > 0) {
            $incomeCode = $disposal->recovery_account_code ?: '711';
            $this->assertDetail($incomeCode);

            $recoveryLines = [
                [
                    'account'     => '1111',
                    'debit'       => (float) $disposal->recovery_amount + (float) $disposal->recovery_vat_amount,
                    'credit'      => 0,
                    'description' => "Thu từ thanh lý CCDC: {$tool->name}",
                ],
                [
                    'account'     => $incomeCode,
                    'debit'       => 0,
                    'credit'      => (float) $disposal->recovery_amount,
                    'description' => "Doanh thu thanh lý CCDC: {$tool->name}",
                ],
            ];

            if ((float) $disposal->recovery_vat_amount > 0) {
                $this->assertDetail('3331');
                $recoveryLines[] = [
                    'account'     => '3331',
                    'debit'       => 0,
                    'credit'      => (float) $disposal->recovery_vat_amount,
                    'description' => 'VAT thanh lý CCDC',
                ];
            }

            $je2    = $this->accounting->post(
                description: "Doanh thu thanh lý CCDC: {$tool->code}",
                date: $date,
                lines: $recoveryLines,
                referenceType: 'small_tool_disposal',
                referenceId: $disposal->id,
                isAuto: false,
                journalSourceType: 'small_tool_disposal',
            );
            $jeIds[] = $je2->id;
        }

        return $jeIds;
    }

    // --------------------------------------------------
    // Helper
    // --------------------------------------------------

    private function resolveCreditAccount(string $paymentType, ?Fund $fund): string
    {
        if ($paymentType === 'payable') return '3311';
        if ($fund) return $fund->account_code ?: ($fund->type === 'bank' ? '1121' : '1111');
        return $paymentType === 'bank' ? '1121' : '1111';
    }

    private function assertDetail(string $code): void
    {
        $account = AccountCode::where('code', $code)->first();
        if (! $account) {
            throw new \InvalidArgumentException("Tài khoản {$code} không tồn tại.");
        }
        if (! $account->is_detail) {
            throw new \InvalidArgumentException("Tài khoản {$code} là tài khoản tổng hợp, không hạch toán trực tiếp.");
        }
    }
}
