<?php

namespace App\Services;

use App\Enums\SmallToolStatus;
use App\Models\SmallTool;
use App\Models\SmallToolIssue;
use App\Models\SmallToolIssueItem;
use App\Models\SmallToolReceipt;
use App\Models\SmallToolReceiptItem;
use App\Models\SmallToolTransfer;
use App\Models\SmallToolDisposal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\AccountingService;

class SmallToolService
{
    public function __construct(
        protected SmallToolJournalService $journal,
        protected SmallToolAllocationService $allocation,
        protected AccountingService $accounting,
    ) {}

    // =========================================================
    // RECEIPT (Phiếu nhập kho CCDC)
    // =========================================================

    public function confirmReceipt(SmallToolReceipt $receipt): void
    {
        if ($receipt->status !== 'draft') {
            throw new \RuntimeException('Chỉ xác nhận phiếu nhập ở trạng thái nháp.');
        }

        DB::transaction(function () use ($receipt) {
            $receipt->load('items.tool', 'fund');

            $je = $this->journal->createReceiptJournal($receipt);
            $receipt->update(['status' => 'confirmed', 'journal_entry_id' => $je->id]);

            foreach ($receipt->items as $item) {
                $item->tool->update(['status' => SmallToolStatus::InStock->value]);
            }
        });
    }

    public function cancelReceipt(SmallToolReceipt $receipt): void
    {
        if ($receipt->status !== 'draft') {
            throw new \RuntimeException('Chỉ hủy phiếu nhập ở trạng thái nháp.');
        }
        $receipt->update(['status' => 'cancelled']);
    }

    public function recallReceipt(SmallToolReceipt $receipt): void
    {
        if ($receipt->status !== 'confirmed') {
            throw new \RuntimeException('Chỉ thu hồi phiếu nhập đã xác nhận.');
        }

        // Kiểm tra các CCDC trong phiếu chưa xuất dùng
        foreach ($receipt->items as $item) {
            if (! in_array($item->tool->status->value, ['in_stock', 'cancelled'])) {
                throw new \RuntimeException("CCDC {$item->tool->code} đã xuất dùng/phân bổ, không thể thu hồi phiếu nhập.");
            }
        }

        DB::transaction(function () use ($receipt) {
            if ($receipt->journal_entry_id) {
                $je = \App\Models\JournalEntry::find($receipt->journal_entry_id);
                if ($je) $this->accounting->reverse($je, 'Thu hồi phiếu nhập CCDC ' . $receipt->code);
            }

            foreach ($receipt->items as $item) {
                $item->tool->update(['status' => SmallToolStatus::Cancelled->value]);
            }

            $receipt->update(['status' => 'cancelled', 'journal_entry_id' => null]);
        });
    }

    // =========================================================
    // DIRECT USE (Mua CCDC dùng ngay, không qua kho)
    // =========================================================

    public function confirmDirectTool(SmallTool $tool): void
    {
        if ($tool->acquisition_type !== 'direct' || $tool->status !== SmallToolStatus::Draft) {
            throw new \RuntimeException('Chỉ áp dụng cho CCDC dùng ngay đang ở trạng thái nháp.');
        }

        DB::transaction(function () use ($tool) {
            $je = $this->journal->createDirectUseJournal($tool);

            $newStatus = $tool->recognition_method === 'allocation'
                ? SmallToolStatus::Allocating
                : SmallToolStatus::InUse;

            $tool->update([
                'status'                       => $newStatus->value,
                'acquisition_journal_entry_id' => $je->id,
            ]);

            // Tạo lịch phân bổ nếu cần
            if ($tool->recognition_method === 'allocation' && $tool->allocation_periods) {
                $this->allocation->buildSchedule($tool);
            }
        });
    }

    // =========================================================
    // ISSUE (Phiếu xuất dùng CCDC)
    // =========================================================

    public function confirmIssue(SmallToolIssue $issue): void
    {
        if ($issue->status !== 'draft') {
            throw new \RuntimeException('Chỉ xác nhận phiếu xuất ở trạng thái nháp.');
        }

        DB::transaction(function () use ($issue) {
            $issue->load('items.tool');
            $totalAmount = 0;

            foreach ($issue->items as $item) {
                $tool = $item->tool;
                if (! $tool->canIssue()) {
                    throw new \RuntimeException("CCDC {$tool->code} không ở trạng thái trong kho.");
                }

                $amount = (float) $item->amount;
                $totalAmount += $amount;

                $je = $this->journal->createIssueJournal(
                    $tool,
                    $amount,
                    $issue->recognition_method,
                    $issue->expense_account_code,
                    $issue->project_id,
                );

                $newStatus = $issue->recognition_method === 'allocation'
                    ? SmallToolStatus::Allocating
                    : SmallToolStatus::InUse;

                $tool->update([
                    'status'                  => $newStatus->value,
                    'in_service_date'         => $issue->issue_date,
                    'department'              => $issue->department ?: $tool->department,
                    'responsible_employee_id' => $issue->responsible_employee_id ?: $tool->responsible_employee_id,
                    'project_id'              => $issue->project_id ?: $tool->project_id,
                    'issue_journal_entry_id'  => $je->id,
                    'recognition_method'      => $issue->recognition_method,
                    'allocation_periods'      => $issue->allocation_periods,
                    'allocation_start_date'   => $issue->allocation_start_date,
                    'expense_account_code'    => $issue->expense_account_code,
                ]);

                if ($issue->recognition_method === 'allocation' && $issue->allocation_periods) {
                    $this->allocation->buildSchedule($tool);
                }
            }

            $issue->update([
                'status'           => 'confirmed',
                'total_amount'     => $totalAmount,
                'journal_entry_id' => null, // individual JEs per tool
            ]);
        });
    }

    public function cancelIssue(SmallToolIssue $issue): void
    {
        if ($issue->status !== 'draft') {
            throw new \RuntimeException('Chỉ hủy phiếu xuất ở trạng thái nháp.');
        }
        $issue->update(['status' => 'cancelled']);
    }

    public function recallIssue(SmallToolIssue $issue): void
    {
        if ($issue->status !== 'confirmed') {
            throw new \RuntimeException('Chỉ thu hồi phiếu xuất đã xác nhận.');
        }

        DB::transaction(function () use ($issue) {
            foreach ($issue->items as $item) {
                $tool = $item->tool;

                // Không thu hồi nếu đã có phân bổ posted
                $hasPostedAlloc = $tool->allocations()->where('status', 'posted')->exists();
                if ($hasPostedAlloc) {
                    throw new \RuntimeException("CCDC {$tool->code} đã có kỳ phân bổ được duyệt. Hủy phân bổ trước.");
                }

                // Đảo bút toán xuất dùng
                if ($tool->issue_journal_entry_id) {
                    $je = \App\Models\JournalEntry::find($tool->issue_journal_entry_id);
                    if ($je) $this->accounting->reverse($je, 'Thu hồi phiếu xuất CCDC ' . $issue->code);
                }

                // Xóa lịch phân bổ pending
                $tool->allocations()->where('status', 'pending')->delete();

                $tool->update([
                    'status'                 => SmallToolStatus::InStock->value,
                    'in_service_date'        => null,
                    'issue_journal_entry_id' => null,
                    'periods_allocated'      => 0,
                    'total_allocated'        => 0,
                ]);
            }

            $issue->update(['status' => 'cancelled']);
        });
    }

    // =========================================================
    // TRANSFER (Điều chuyển)
    // =========================================================

    public function createTransfer(SmallTool $tool, array $data): SmallToolTransfer
    {
        if (! in_array($tool->status->value, ['in_stock', 'in_use', 'allocating'])) {
            throw new \RuntimeException('CCDC không ở trạng thái có thể điều chuyển.');
        }

        return DB::transaction(function () use ($tool, $data) {
            $affectsFuture = false;
            $newExpenseAccount = $data['new_expense_account_code'] ?? null;

            if ($newExpenseAccount && $newExpenseAccount !== $tool->expense_account_code) {
                $affectsFuture = true;
                // Cập nhật TK chi phí trên lịch phân bổ pending
                $tool->allocations()
                    ->where('status', 'pending')
                    ->update(['debit_account' => $newExpenseAccount]);
            }

            $transfer = SmallToolTransfer::create([
                ...$data,
                'code'                      => SmallToolTransfer::generateCode(),
                'small_tool_id'             => $tool->id,
                'affects_future_allocation' => $affectsFuture,
                'created_by'                => auth()->id(),
            ]);

            $tool->update(array_filter([
                'department'              => $data['to_department']     ?? $tool->department,
                'responsible_employee_id' => $data['to_employee_id']    ?? $tool->responsible_employee_id,
                'project_id'              => $data['to_project_id']     ?? $tool->project_id,
                'warehouse_id'            => $data['to_warehouse_id']   ?? $tool->warehouse_id,
                'expense_account_code'    => $newExpenseAccount         ?? $tool->expense_account_code,
            ]));

            return $transfer;
        });
    }

    // =========================================================
    // DISPOSAL (Hỏng/Mất/Thanh lý)
    // =========================================================

    public function approveDisposal(SmallToolDisposal $disposal): void
    {
        if ($disposal->status !== 'draft') {
            throw new \RuntimeException('Chỉ duyệt xử lý CCDC ở trạng thái nháp.');
        }

        DB::transaction(function () use ($disposal) {
            $tool = $disposal->tool;

            // Hủy lịch phân bổ pending
            $tool->allocations()->where('status', 'pending')->update(['status' => 'cancelled']);

            // Snapshot giá trị còn lại
            $netValue = $tool->total_remaining;
            $disposal->update(['net_value_snapshot' => $netValue]);

            // Tạo bút toán
            $jeIds = $this->journal->createDisposalJournals($disposal->fresh());

            $newStatus = match($disposal->disposal_type) {
                'broken'     => SmallToolStatus::Broken,
                'lost'       => SmallToolStatus::Lost,
                'liquidated' => SmallToolStatus::Disposed,
                default      => SmallToolStatus::Disposed,
            };

            $tool->update([
                'status'          => $newStatus->value,
                'total_allocated' => $tool->original_cost, // đánh dấu đã xử lý hết
            ]);

            $disposal->update([
                'status'            => 'approved',
                'journal_entry_ids' => $jeIds,
                'approved_by'       => auth()->id(),
                'approved_at'       => now(),
            ]);
        });
    }
}
