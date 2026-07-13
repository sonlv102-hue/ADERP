<?php

namespace App\Services;

use App\Enums\AccountingPostingStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\AccountingPostingJob;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountingService
{
    /**
     * Tạo và post một bút toán kép.
     *
     * $lines = [
     *   ['account' => '131', 'debit' => 5000000, 'credit' => 0, 'description' => '...'],
     *   ['account' => '511', 'debit' => 0, 'credit' => 4545455, 'description' => '...'],
     *   ['account' => '3331', 'debit' => 0, 'credit' => 454545, 'description' => '...'],
     * ]
     */
    public function post(
        string $description,
        CarbonInterface $date,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        bool $isAuto = false,
        ?string $notes = null,
        ?string $journalSourceType = null,
        bool $excludeFromPeriodMovement = false,
        ?string $fiscalPeriod = null,
        bool $skipParentAccountCheck = false,
    ): JournalEntry {
        $this->validateLines($lines, $skipParentAccountCheck);
        $this->checkPeriodOpen($date);

        // fiscal_period tự suy ra từ entry_date nếu không truyền
        $resolvedFiscalPeriod = $fiscalPeriod ?? $date->format('Y-m');

        return DB::transaction(function () use (
            $description, $date, $lines, $referenceType, $referenceId, $isAuto, $notes,
            $journalSourceType, $excludeFromPeriodMovement, $resolvedFiscalPeriod
        ) {
            // Bút toán tự động (is_auto=true) tạo ở trạng thái draft để kế toán duyệt trước khi hạch toán
            $status = $isAuto ? 'draft' : 'posted';

            $supplierId = null;
            $purchaseContractId = null;
            $purchaseOrderId = null;
            $supplierPrepaymentId = null;

            if ($referenceType === 'cash_voucher') {
                $voucher = \App\Models\CashVoucher::find($referenceId);
                if ($voucher && $voucher->reference_type === \App\Models\SupplierOpeningAdvance::class) {
                    $advance = \App\Models\SupplierOpeningAdvance::find($voucher->reference_id);
                    if ($advance) {
                        $supplierId = $advance->supplier_id;
                        $purchaseContractId = $advance->purchase_contract_id;
                        $purchaseOrderId = $advance->purchase_order_id;
                        $supplierPrepaymentId = $advance->id;
                    }
                }
            } elseif ($referenceType === \App\Models\SupplierOpeningAdvance::class || $referenceType === 'supplier_opening_advances') {
                $advance = \App\Models\SupplierOpeningAdvance::find($referenceId);
                if ($advance) {
                    $supplierId = $advance->supplier_id;
                    $purchaseContractId = $advance->purchase_contract_id;
                    $purchaseOrderId = $advance->purchase_order_id;
                    $supplierPrepaymentId = $advance->id;
                }
            } elseif ($referenceType === \App\Models\SupplierAdvanceAllocation::class || $referenceType === 'supplier_advance_allocations') {
                $allocation = \App\Models\SupplierAdvanceAllocation::find($referenceId);
                if ($allocation && $allocation->opening_advance_id) {
                    $advance = \App\Models\SupplierOpeningAdvance::find($allocation->opening_advance_id);
                    if ($advance) {
                        $supplierId = $advance->supplier_id;
                        $purchaseContractId = $advance->purchase_contract_id;
                        $purchaseOrderId = $advance->purchase_order_id;
                        $supplierPrepaymentId = $advance->id;
                    }
                }
            }

            $entry = JournalEntry::create([
                'code'                          => JournalEntry::generateCode(),
                'entry_date'                    => $date,
                'description'                   => $description,
                'reference_type'                => $referenceType,
                'reference_id'                  => $referenceId,
                'source_type'                   => $journalSourceType,
                'fiscal_period'                 => $resolvedFiscalPeriod,
                'exclude_from_period_movement'  => $excludeFromPeriodMovement,
                'status'                        => $status,
                'is_auto'                       => $isAuto,
                'created_by'                    => auth()->id() ?? 1,
                'posted_at'                     => $isAuto ? null : now(),
                'notes'                         => $notes,
                'supplier_id'                   => $supplierId,
                'purchase_contract_id'          => $purchaseContractId,
                'purchase_order_id'             => $purchaseOrderId,
                'supplier_prepayment_id'        => $supplierPrepaymentId,
            ]);

            foreach ($lines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account'],
                    'description'      => $line['description'] ?? null,
                    'debit'            => (int) ($line['debit'] ?? 0),
                    'credit'           => (int) ($line['credit'] ?? 0),
                    'sort_order'       => $i,
                    'project_id'       => $line['project_id'] ?? null,
                    'partner_type'     => $line['partner_type'] ?? null,
                    'partner_id'       => $line['partner_id'] ?? null,
                ]);
            }

            // Mở kỳ kế toán nếu chưa tồn tại
            AccountingPeriod::findOrCreateForDate($date);

            return $entry;
        });
    }

    /** Duyệt bút toán nháp → posted */
    public function markPosted(JournalEntry $entry): void
    {
        if ($entry->status !== 'draft') {
            throw new \RuntimeException('Chỉ có thể duyệt bút toán đang ở trạng thái Nháp.');
        }
        $this->checkPeriodOpen(Carbon::parse($entry->entry_date));
        $entry->update(['status' => 'posted', 'posted_at' => now()]);
    }

    /**
     * Đảo bút toán nếu đã posted, hoặc xóa nếu vẫn còn draft.
     * Dùng trong các nghiệp vụ hủy/thu hồi để xử lý cả 2 trạng thái.
     */
    public function reverseOrDelete(string $refType, int $refId, string $reason): void
    {
        $entry = JournalEntry::where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->whereIn('status', ['posted', 'draft'])
            ->whereRaw("description NOT LIKE 'Đảo:%'")
            ->first();

        if (! $entry) return;

        if ($entry->status === 'draft') {
            $entry->lines()->delete();
            $entry->delete();
            return;
        }

        $this->reverse($entry, $reason);
    }

    /** Đảo bút toán (tạo bút toán ngược) */
    public function reverse(JournalEntry $entry, ?string $reason = null): JournalEntry
    {
        if ($entry->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể đảo bút toán đã hạch toán.');
        }

        // Chặn đảo bút toán đảo — tránh chuỗi "Đảo: Đảo: Đảo:..."
        if (str_starts_with($entry->description, 'Đảo:')) {
            throw new \RuntimeException('Không thể đảo một bút toán đảo. Liên hệ kế toán trưởng nếu cần điều chỉnh.');
        }

        $entry->load('lines');
        $reversedLines = $entry->lines->map(fn ($l) => [
            'account'     => $l->account_code,
            'debit'       => (int) $l->credit,
            'credit'      => (int) $l->debit,
            'description' => $l->description,
            'project_id'  => $l->project_id,
        ])->all();

        // Bút toán đảo luôn posted ngay (isAuto=false) — đây là bút toán điều chỉnh, không cần duyệt lại
        // skipParentAccountCheck=true: cho phép đảo JE legacy dùng TK tổng hợp (vd 331) mà không bị chặn
        $reversal = $this->post(
            description: 'Đảo: ' . $entry->description,
            date: now(),
            lines: $reversedLines,
            referenceType: $entry->reference_type,
            referenceId: $entry->reference_id,
            isAuto: false,
            notes: $reason,
            skipParentAccountCheck: true,
        );

        $entry->update(['status' => 'reversed', 'reversed_by_id' => $reversal->id]);

        return $reversal;
    }

    /** Tạo bút toán thủ công ở trạng thái Nháp (không kiểm tra kỳ kế toán). */
    public function createDraft(
        string $description,
        CarbonInterface $date,
        array $lines,
        ?string $notes = null,
    ): JournalEntry {
        $this->validateLines($lines);

        return DB::transaction(function () use ($description, $date, $lines, $notes) {
            $entry = JournalEntry::create([
                'code'          => JournalEntry::generateCode(),
                'entry_date'    => $date,
                'description'   => $description,
                'status'        => 'draft',
                'is_auto'       => false,
                'created_by'    => auth()->id() ?? 1,
                'notes'         => $notes,
                'fiscal_period' => $date->format('Y-m'),
            ]);

            foreach ($lines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account'],
                    'description'      => $line['description'] ?? null,
                    'debit'            => (int) ($line['debit'] ?? 0),
                    'credit'           => (int) ($line['credit'] ?? 0),
                    'sort_order'       => $i,
                    'partner_type'     => $line['partner_type'] ?? null,
                    'partner_id'       => $line['partner_id'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Cập nhật dòng bút toán (chỉ khi draft).
     * Bút toán tự động bị sửa lần đầu: snapshot original_lines.
     */
    public function updateLines(JournalEntry $entry, array $lines, ?string $editReason = null): void
    {
        if ($entry->status !== 'draft') {
            throw new \RuntimeException('Chỉ có thể sửa dòng bút toán khi ở trạng thái Nháp.');
        }
        $this->validateLines($lines);

        DB::transaction(function () use ($entry, $lines, $editReason) {
            if ($entry->is_auto && ! $entry->edited_by_user) {
                $entry->load('lines');
                $snapshot = $entry->lines->map(fn ($l) => [
                    'account_code' => $l->account_code,
                    'debit'        => (int) $l->debit,
                    'credit'       => (int) $l->credit,
                    'description'  => $l->description,
                ])->toArray();
                $entry->update([
                    'original_lines' => $snapshot,
                    'edited_by_user' => true,
                    'edit_reason'    => $editReason,
                ]);
            } elseif ($entry->edited_by_user && $editReason) {
                $entry->update(['edit_reason' => $editReason]);
            }

            $entry->lines()->delete();
            foreach ($lines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account'],
                    'description'      => $line['description'] ?? null,
                    'debit'            => (int) ($line['debit'] ?? 0),
                    'credit'           => (int) ($line['credit'] ?? 0),
                    'sort_order'       => $i,
                    'partner_type'     => $line['partner_type'] ?? null,
                    'partner_id'       => $line['partner_id'] ?? null,
                ]);
            }
        });
    }

    /** Thu hồi hạch toán: posted → draft (chỉ khi kỳ chưa khóa). */
    public function unpost(JournalEntry $entry): void
    {
        if ($entry->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể thu hồi bút toán đã hạch toán.');
        }
        $this->checkPeriodOpen(Carbon::parse($entry->entry_date));
        $entry->update(['status' => 'draft', 'posted_at' => null]);
    }

    /** Khôi phục dòng bút toán về trạng thái gốc (trước khi kế toán sửa). */
    public function restoreOriginalLines(JournalEntry $entry): void
    {
        if (! $entry->edited_by_user || ! $entry->original_lines) {
            throw new \RuntimeException('Không có bản sao bút toán gốc để khôi phục.');
        }
        if ($entry->status !== 'draft') {
            throw new \RuntimeException('Thu hồi hạch toán trước khi khôi phục dòng bút toán.');
        }

        $originalLines = $entry->original_lines;

        DB::transaction(function () use ($entry, $originalLines) {
            $entry->lines()->delete();
            foreach ($originalLines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account_code'],
                    'debit'            => (int) $line['debit'],
                    'credit'           => (int) $line['credit'],
                    'description'      => $line['description'] ?? null,
                    'sort_order'       => $i,
                ]);
            }
            $entry->update([
                'edited_by_user' => false,
                'edit_reason'    => null,
                'original_lines' => null,
            ]);
        });
    }

    /**
     * Số dư tài khoản trong khoảng thời gian.
     * Trả về: ['debit' => float, 'credit' => float, 'balance' => float]
     */
    public function getAccountBalance(string $accountCode, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = JournalEntryLine::where('account_code', $accountCode)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'));

        if ($from) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '>=', $from));
        }
        if ($to) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $to));
        }

        $debit  = (float) $query->sum('debit');
        $credit = (float) $query->sum('credit');

        $account = AccountCode::find($accountCode);
        $balance = $account
            ? ($account->normal_balance === 'debit' ? $debit - $credit : $credit - $debit)
            : $debit - $credit;

        return compact('debit', 'credit', 'balance');
    }

    /**
     * Số dư nhiều tài khoản cùng lúc (tránh N+1).
     * Trả về: ['111' => ['debit'=>..., 'credit'=>..., 'balance'=>...], ...]
     */
    public function getMultipleBalances(array $accountCodes, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = JournalEntryLine::whereIn('account_code', $accountCodes)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->select('account_code', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'))
            ->groupBy('account_code');

        if ($from) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '>=', $from));
        }
        if ($to) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $to));
        }

        $accounts = AccountCode::whereIn('code', $accountCodes)->get()->keyBy('code');
        $rows = $query->get()->keyBy('account_code');

        $result = [];
        foreach ($accountCodes as $code) {
            $row    = $rows->get($code);
            $acc    = $accounts->get($code);
            $debit  = (float) ($row?->total_debit ?? 0);
            $credit = (float) ($row?->total_credit ?? 0);
            $balance = $acc
                ? ($acc->normal_balance === 'debit' ? $debit - $credit : $credit - $debit)
                : $debit - $credit;
            $result[$code] = compact('debit', 'credit', 'balance');
        }

        return $result;
    }

    /**
     * Auto-posting với tracking. Không throw exception — ghi status vào accounting_posting_jobs.
     * Idempotent: nếu job đã posted thì return JE cũ, không tạo trùng.
     */
    public function tryPost(
        string $description,
        CarbonInterface $date,
        array $lines,
        string $sourceType,
        int $sourceId,
        string $postingType,
        bool $isAuto = true,
    ): ?JournalEntry {
        $job = AccountingPostingJob::firstOrNew([
            'source_type'  => $sourceType,
            'source_id'    => $sourceId,
            'posting_type' => $postingType,
        ]);

        if ($job->exists && $job->status === AccountingPostingStatus::Posted) {
            return $job->journalEntry;
        }

        $job->fill([
            'description' => $description,
            'posting_date' => $date->toDateString(),
            'lines'        => $lines,
            'status'       => AccountingPostingStatus::Pending,
            'created_by'   => auth()->id() ?? 1,
        ]);
        $job->attempts = ($job->attempts ?? 0) + 1;
        $job->last_attempted_at = now();

        try {
            $entry = $this->post($description, $date, $lines, $sourceType, $sourceId, $isAuto);
            $job->status           = AccountingPostingStatus::Posted;
            $job->journal_entry_id = $entry->id;
            $job->error_code       = null;
            $job->error_message    = null;
            $job->posted_at        = now();
            $job->save();
            return $entry;
        } catch (\Throwable $e) {
            $job->status        = AccountingPostingStatus::Failed;
            $job->error_code    = $this->classifyError($e);
            $job->error_message = $e->getMessage();
            $job->save();
            Log::warning("AccountingService::tryPost [{$sourceType}#{$sourceId}/{$postingType}]: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Retry một posting job đã failed/pending. Dùng lại description/date/lines đã lưu.
     * Nếu kỳ vẫn đóng → throw exception để controller báo lỗi cho user.
     */
    public function retryJob(AccountingPostingJob $job): JournalEntry
    {
        if ($job->status === AccountingPostingStatus::Posted && $job->journalEntry) {
            return $job->journalEntry;
        }

        $job->attempts++;
        $job->last_attempted_at = now();

        try {
            $entry = $this->post(
                $job->description,
                Carbon::parse($job->posting_date),
                $job->lines,
                $job->source_type,
                $job->source_id,
                true,
            );
            $job->status           = AccountingPostingStatus::Posted;
            $job->journal_entry_id = $entry->id;
            $job->error_code       = null;
            $job->error_message    = null;
            $job->posted_at        = now();
            $job->save();
            return $entry;
        } catch (\Throwable $e) {
            $job->status        = AccountingPostingStatus::Failed;
            $job->error_code    = $this->classifyError($e);
            $job->error_message = $e->getMessage();
            $job->save();
            throw $e;
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function classifyError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'đã đóng') || str_contains($msg, 'đã khóa')) {
            return 'PERIOD_CLOSED';
        }
        if ($e instanceof \InvalidArgumentException) {
            return 'INVALID_DATA';
        }
        return 'UNKNOWN';
    }

    private function validateLines(array $lines, bool $skipParentAccountCheck = false): void
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('Bút toán phải có ít nhất 2 dòng.');
        }

        $totalDebit  = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));

        if (abs($totalDebit - $totalCredit) >= 1) {
            throw new \InvalidArgumentException(
                "Bút toán không cân: Nợ={$totalDebit}, Có={$totalCredit}."
            );
        }

        // Không được ghi bút toán vào tài khoản tổng hợp (is_detail = false)
        // Ngoại lệ: bút toán đảo (reverse) được phép dùng TK cha của JE gốc để cancel đúng entry
        if (! $skipParentAccountCheck) {
            $codes = array_unique(array_column($lines, 'account'));
            $parentCodes = AccountCode::whereIn('code', $codes)
                ->where('is_detail', false)
                ->pluck('code')
                ->toArray();

            if (! empty($parentCodes)) {
                $list = implode(', ', $parentCodes);
                throw new \InvalidArgumentException(
                    "Tài khoản tổng hợp không được ghi bút toán trực tiếp: {$list}. " .
                    "Vui lòng chọn tài khoản chi tiết (cấp cuối cùng)."
                );
            }
        }
    }

    private function checkPeriodOpen(CarbonInterface $date): void
    {
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if ($period && $period->status !== 'open') {
            throw new \RuntimeException(
                "Kỳ kế toán {$period->label()} đã đóng/khóa. Không thể hạch toán."
            );
        }
    }
}
