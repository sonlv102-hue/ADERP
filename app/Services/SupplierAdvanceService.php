<?php

namespace App\Services;

use App\Enums\CashVoucherStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\ArApOpeningBalance;
use App\Models\BankAccount;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierOpeningAdvance;
use App\Services\AccountingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierAdvanceService
{
    public function __construct(
        private AccountingService $accounting,
        private CashVoucherService $cashVoucherService,
    ) {}
    public function create(array $data): SupplierOpeningAdvance
    {
        $data['remaining_amount'] = $data['amount'];
        $data['status']           = 'open';
        $data['advance_type']     = $data['advance_type'] ?? 'opening_balance';
        if (!isset($data['account_code'])) {
            $data['account_code'] = AccountingSettings::get('supplier_advance_account', '331UT');
        }
        $data['created_by'] = auth()->id();
        if (!isset($data['fiscal_year']) || $data['fiscal_year'] === null) {
            $data['fiscal_year'] = DB::getDriverName() === 'pgsql' ? null : (int) date('Y');
        }
        return SupplierOpeningAdvance::create($data);
    }

    /**
     * Tạo khoản trả trước trong kỳ (không phải số dư đầu kỳ).
     * Khoản này có thể đối trừ vào hóa đơn sau.
     */
    public function createPrepayment(
        int $supplierId,
        float $amount,
        string $date,
        ?string $reference = null,
        ?string $notes = null,
        string $sourceType = 'manual',
        ?int $sourceId = null
    ): SupplierOpeningAdvance {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $supplierId,
            'advance_type'     => 'prepayment',
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'fiscal_year'      => \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? null : (int) date('Y'),
            'opening_date'     => $date,
            'account_code'     => AccountingSettings::get('supplier_advance_account', '331UT'),
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'currency'         => 'VND',
            'reference_no'     => $reference,
            'notes'            => $notes,
            'status'           => 'open',
            'created_by'       => auth()->id(),
        ]);
    }

    public function update(SupplierOpeningAdvance $advance, array $data): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể sửa khoản ứng trước đã có đối trừ đang hoạt động.');
        }
        if (isset($data['amount'])) {
            $data['remaining_amount'] = $data['amount'];
            $data['status']           = 'open';
        }
        $advance->update($data);
    }

    public function cancel(SupplierOpeningAdvance $advance, string $reason): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể hủy khoản ứng trước đã có đối trừ đang hoạt động. Thu hồi các đối trừ trước.');
        }

        DB::transaction(function () use ($advance, $reason) {
            // Khi hủy prepayment: cần cancel CashVoucher liên kết để đảo JE Dr 331UT / Cr fund
            if ($advance->isPrepayment()) {
                $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
                    ->where('reference_id', $advance->id)
                    ->whereIn('status', [CashVoucherStatus::Confirmed->value, CashVoucherStatus::Draft->value])
                    ->first();

                if ($voucher) {
                    $this->cashVoucherService->cancel($voucher);
                } else {
                    // Có JE trực tiếp nhưng không có CashVoucher — đảo JE trực tiếp
                    // Trường hợp này hiếm (legacy). Dùng AccountingService để reverse.
                    $this->accounting->reverseOrDelete(
                        'supplier_advance',
                        $advance->id,
                        "Hủy khoản trả trước NCC: {$reason}"
                    );
                }
            }

            $advance->update([
                'status' => 'cancelled',
                'notes'  => trim(($advance->notes ?? '') . "\nHủy: {$reason}"),
            ]);

            Log::info("SupplierAdvance #{$advance->id} cancelled by user " . auth()->id() . ". Reason: {$reason}");
        });
    }

    /**
     * Thu hồi tiền trả trước — NCC hoàn lại tiền.
     * JE: Dr cash_account / Cr 331UT (hoặc advance.account_code)
     */
    public function refund(SupplierOpeningAdvance $advance, array $data): SupplierAdvanceRefund
    {
        if ($advance->status === 'cancelled') {
            throw new \RuntimeException('Không thể thu hồi khoản đã hủy.');
        }
        if ($advance->status === 'fully_applied' && (float) $advance->remaining_amount <= 0) {
            throw new \RuntimeException('Khoản trả trước đã dùng hết, không có số dư để thu hồi.');
        }

        $amount    = round((float) $data['amount'], 2);
        $remaining = round((float) $advance->remaining_amount, 2);

        if ($amount <= 0) {
            throw new \RuntimeException('Số tiền thu hồi phải lớn hơn 0.');
        }
        if ($amount > $remaining + 0.01) {
            throw new \RuntimeException(
                'Số tiền thu hồi (' . number_format($amount, 0, ',', '.') . ') vượt quá số còn lại (' . number_format($remaining, 0, ',', '.') . ').'
            );
        }

        return DB::transaction(function () use ($advance, $data, $amount) {
            $supplier       = Supplier::findOrFail($advance->supplier_id);
            $advanceAccount = $advance->account_code ?? AccountingSettings::get('supplier_advance_account', '331UT');

            // Xác định TK tiền nhận về
            if ($data['refund_method'] === 'cash') {
                $fund        = Fund::findOrFail($data['fund_id']);
                $cashAccount = $fund->account_code;
            } else {
                $bank        = BankAccount::findOrFail($data['bank_account_id']);
                $cashAccount = $bank->account_code;
            }

            // JE: Dr cashAccount / Cr advanceAccount
            $je = $this->accounting->post(
                description: 'Thu hồi trả trước NCC ' . $supplier->name . ($advance->reference_no ? " ({$advance->reference_no})" : ''),
                date: \Carbon\Carbon::parse($data['refund_date']),
                lines: [
                    [
                        'account'      => $cashAccount,
                        'debit'        => $amount,
                        'credit'       => 0,
                        'description'  => 'Thu tiền hoàn lại NCC ' . $supplier->name,
                        'partner_type' => 'supplier',
                        'partner_id'   => $advance->supplier_id,
                    ],
                    [
                        'account'      => $advanceAccount,
                        'debit'        => 0,
                        'credit'       => $amount,
                        'description'  => 'Giảm trả trước NCC ' . $supplier->name,
                        'partner_type' => 'supplier',
                        'partner_id'   => $advance->supplier_id,
                    ],
                ],
                referenceType: SupplierAdvanceRefund::class,
                referenceId: 0,
                isAuto: false,
            );

            $refund = SupplierAdvanceRefund::create([
                'supplier_advance_id' => $advance->id,
                'supplier_id'         => $advance->supplier_id,
                'refund_date'         => $data['refund_date'],
                'amount'              => $amount,
                'refund_method'       => $data['refund_method'],
                'fund_id'             => $data['fund_id'] ?? null,
                'bank_account_id'     => $data['bank_account_id'] ?? null,
                'journal_entry_id'    => $je?->id,
                'description'         => $data['description'] ?? null,
                'status'              => 'confirmed',
                'created_by'          => auth()->id(),
            ]);

            if ($je) {
                \App\Models\JournalEntry::where('id', $je->id)->update(['reference_id' => $refund->id]);
            }

            $newRefunded  = round((float) $advance->refunded_amount + $amount, 2);
            $newRemaining = round((float) $advance->remaining_amount - $amount, 2);

            $advance->update([
                'refunded_amount'  => $newRefunded,
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining <= 0 ? 'fully_applied' : 'partially_applied',
            ]);

            Log::info("SupplierAdvance #{$advance->id} thu hồi " . number_format($amount, 0, ',', '.') . " đ bởi user " . auth()->id());

            return $refund;
        });
    }

    /**
     * Tạo chứng từ đối trừ ứng trước đầu kỳ với hóa đơn mua hàng.
     * Không tạo bút toán sổ cái — chỉ allocation record.
     */
    public function allocate(
        SupplierOpeningAdvance $advance,
        PurchaseInvoice $invoice,
        float $amount,
        string $allocationDate,
        ?string $reason = null
    ): SupplierAdvanceAllocation {
        if ($advance->supplier_id !== $invoice->supplier_id) {
            throw new \RuntimeException('Khoản ứng trước và hóa đơn phải thuộc cùng nhà cung cấp.');
        }

        if (!$advance->isAvailable()) {
            throw new \RuntimeException('Khoản ứng trước đã dùng hết hoặc đã hủy.');
        }

        $remaining = (float) $advance->remaining_amount;
        if ($amount > $remaining + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá ứng trước còn lại (' . number_format($remaining) . ').'
            );
        }

        $invoiceDue = (float) $invoice->total
            - (float) $invoice->paid_amount
            - (float) $invoice->advance_allocated_amount;

        if ($amount > $invoiceDue + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá số còn phải trả của hóa đơn (' . number_format($invoiceDue) . ').'
            );
        }

        if (!in_array($invoice->status, [PurchaseInvoiceStatus::Valid, PurchaseInvoiceStatus::PartialPaid])) {
            throw new \RuntimeException('Chỉ đối trừ được hóa đơn ở trạng thái Hợp lệ hoặc Thanh toán một phần.');
        }

        return DB::transaction(function () use ($advance, $invoice, $amount, $allocationDate, $reason) {
            // JE đối trừ: Dr payableAccount (giảm phải trả NCC) / Cr advanceAccount (giảm trả trước NCC)
            // Tạo JE khi advanceAccount khác payableAccount (luồng chuẩn: 331UT ≠ 3311)
            // Không tạo JE khi advance cùng TK với payable (dữ liệu cũ/đặc biệt)
            $supplier       = Supplier::findOrFail($advance->supplier_id);
            $payableAccount = $supplier->getPayableAccount();
            $advanceAccount = $advance->account_code ?? AccountingSettings::get('supplier_advance_account', '331UT');

            $jeId = null;
            if ($advanceAccount !== $payableAccount) {
                $je = $this->accounting->post(
                    description: "Đối trừ trả trước NCC #{$advance->id} vào HĐ {$invoice->code}",
                    date: \Carbon\Carbon::parse($allocationDate),
                    lines: [
                        [
                            'account'      => $payableAccount,
                            'debit'        => $amount,
                            'credit'       => 0,
                            'description'  => "Đối trừ phải trả NCC {$supplier->name} HĐ {$invoice->code}",
                            'partner_type' => 'supplier',
                            'partner_id'   => $advance->supplier_id,
                        ],
                        [
                            'account'      => $advanceAccount,
                            'debit'        => 0,
                            'credit'       => $amount,
                            'description'  => "Giảm trả trước NCC {$supplier->name}",
                            'partner_type' => 'supplier',
                            'partner_id'   => $advance->supplier_id,
                        ],
                    ],
                    referenceType: SupplierAdvanceAllocation::class,
                    referenceId: 0,
                    isAuto: false,
                );
                $jeId = $je?->id;
            }

            $allocation = SupplierAdvanceAllocation::create([
                'supplier_id'         => $advance->supplier_id,
                'opening_advance_id'  => $advance->id,
                'purchase_invoice_id' => $invoice->id,
                'allocation_date'     => $allocationDate,
                'allocated_amount'    => $amount,
                'status'              => 'active',
                'reason'              => $reason,
                'journal_entry_id'    => $jeId,
                'created_by'          => auth()->id(),
            ]);

            // Update JE referenceId now that we have allocation->id
            if ($jeId) {
                \App\Models\JournalEntry::where('id', $jeId)
                    ->update(['reference_id' => $allocation->id]);
            }

            $newRemaining = round((float) $advance->remaining_amount - $amount, 2);
            $advance->update([
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining <= 0 ? 'fully_applied' : 'partially_applied',
            ]);

            $newAllocated = round((float) $invoice->advance_allocated_amount + $amount, 2);
            $invoice->update(['advance_allocated_amount' => $newAllocated]);
            $this->recalculateInvoiceStatus($invoice->fresh());

            Log::info("SupplierAdvance #{$advance->id} đối trừ " . number_format($amount) . " đ vào hóa đơn #{$invoice->id} ({$invoice->code})");

            return $allocation;
        });
    }

    /**
     * Thu hồi chứng từ đối trừ — hoàn lại remaining cho ứng trước và giảm advance_allocated_amount.
     */
    public function reverse(SupplierAdvanceAllocation $allocation, string $reason): void
    {
        if ($allocation->isReversed()) {
            throw new \RuntimeException('Chứng từ đối trừ này đã bị thu hồi trước đó.');
        }

        DB::transaction(function () use ($allocation, $reason) {
            $advance = $allocation->advance;
            $amount  = (float) $allocation->allocated_amount;

            // Đảo JE đối trừ nếu có
            $reversalEntryId = null;
            if ($allocation->journal_entry_id) {
                $je = \App\Models\JournalEntry::find($allocation->journal_entry_id);
                if ($je && $je->status === 'posted') {
                    $reversalJe      = $this->accounting->reverse($je, "Thu hồi đối trừ trả trước: {$reason}");
                    $reversalEntryId = $reversalJe?->id;
                }
            }

            $allocation->update([
                'status'            => 'reversed',
                'reason'            => trim(($allocation->reason ?? '') . "\nThu hồi: {$reason}"),
                'reversed_by'       => auth()->id(),
                'reversed_at'       => now(),
                'reversal_entry_id' => $reversalEntryId,
                'reverse_reason'    => $reason,
            ]);

            $newRemaining = round((float) $advance->remaining_amount + $amount, 2);
            $advance->update([
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining >= (float) $advance->amount ? 'open' : 'partially_applied',
            ]);

            if ($allocation->purchase_invoice_id) {
                $invoice      = $allocation->invoice;
                $newAllocated = round(max(0, (float) $invoice->advance_allocated_amount - $amount), 2);
                $invoice->update(['advance_allocated_amount' => $newAllocated]);
                $this->recalculateInvoiceStatus($invoice->fresh());
            } elseif ($allocation->ar_ap_opening_balance_id) {
                $ob = $allocation->openingBalance;
                $ob->update(['remaining_amount' => round((float) $ob->remaining_amount + $amount, 2)]);
            }
        });
    }

    /**
     * Đối trừ ứng trước NCC với công nợ đầu kỳ AP.
     * Không tạo bút toán — chỉ allocation record + giảm remaining trên cả hai phía.
     */
    public function allocateToOpeningBalance(
        SupplierOpeningAdvance $advance,
        ArApOpeningBalance $openingBalance,
        float $amount,
        string $allocationDate,
        ?string $reason = null
    ): SupplierAdvanceAllocation {
        if ($advance->supplier_id !== $openingBalance->supplier_id) {
            throw new \RuntimeException('Khoản ứng trước và công nợ đầu kỳ phải thuộc cùng nhà cung cấp.');
        }
        if ($openingBalance->type !== 'ap') {
            throw new \RuntimeException('Chỉ đối trừ được công nợ phải trả (AP).');
        }
        if (!$advance->isAvailable()) {
            throw new \RuntimeException('Khoản ứng trước đã dùng hết hoặc đã hủy.');
        }

        $advRemaining = (float) $advance->remaining_amount;
        if ($amount > $advRemaining + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá ứng trước còn lại (' . number_format($advRemaining) . ').'
            );
        }

        $obRemaining = (float) $openingBalance->remaining_amount;
        if ($amount > $obRemaining + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá công nợ còn lại (' . number_format($obRemaining) . ').'
            );
        }

        return DB::transaction(function () use ($advance, $openingBalance, $amount, $allocationDate, $reason, $advRemaining, $obRemaining) {
            $allocation = SupplierAdvanceAllocation::create([
                'supplier_id'              => $advance->supplier_id,
                'opening_advance_id'       => $advance->id,
                'purchase_invoice_id'      => null,
                'ar_ap_opening_balance_id' => $openingBalance->id,
                'allocation_date'          => $allocationDate,
                'allocated_amount'         => $amount,
                'status'                   => 'active',
                'reason'                   => $reason,
                'created_by'               => auth()->id(),
            ]);

            $newAdvRemaining = round($advRemaining - $amount, 2);
            $advance->update([
                'remaining_amount' => $newAdvRemaining,
                'status'           => $newAdvRemaining <= 0 ? 'fully_applied' : 'partially_applied',
            ]);

            $openingBalance->update(['remaining_amount' => round($obRemaining - $amount, 2)]);

            Log::info("SupplierAdvance #{$advance->id} đối trừ " . number_format($amount) . " đ vào công nợ ĐK #{$openingBalance->id}");

            return $allocation;
        });
    }

    /**
     * Xóa mềm khoản trả trước NCC.
     * - cancelled: chỉ soft delete, không cần đảo JE.
     * - open: hủy CashVoucher/JE trước rồi mới soft delete.
     * - partially_applied / fully_applied: từ chối.
     */
    public function deleteSafely(SupplierOpeningAdvance $advance, ?string $reason = null): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể xóa khi còn đối trừ đang hoạt động. Thu hồi đối trừ trước.');
        }

        if (in_array($advance->status, ['fully_applied', 'partially_applied'])) {
            throw new \RuntimeException('Không thể xóa khoản đã đối trừ vào hóa đơn. Chỉ có thể xem lịch sử.');
        }

        DB::transaction(function () use ($advance, $reason) {
            // Nếu còn dư, cần hủy CashVoucher + đảo JE trước khi xóa
            if ($advance->status === 'open') {
                $this->cancel($advance, $reason ?? 'Xóa khoản trả trước');
                $advance->refresh();
            }

            $advance->update([
                'deleted_by'    => auth()->id(),
                'delete_reason' => $reason,
            ]);
            $advance->delete();

            Log::info("SupplierAdvance #{$advance->id} soft-deleted by user " . auth()->id() . ". Reason: {$reason}");
        });
    }

    /**
     * Danh sách ứng trước còn dư của một NCC.
     */
    public function getAvailable(int $supplierId): Collection
    {
        return SupplierOpeningAdvance::where('supplier_id', $supplierId)
            ->available()
            ->with('supplier')
            ->orderBy('opening_date')
            ->get();
    }

    /**
     * Tổng ứng trước còn lại của một NCC.
     */
    public function totalAvailable(int $supplierId): float
    {
        return (float) SupplierOpeningAdvance::where('supplier_id', $supplierId)
            ->available()
            ->sum('remaining_amount');
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private function recalculateInvoiceStatus(PurchaseInvoice $invoice): void
    {
        $total     = (float) $invoice->total;
        $paid      = (float) $invoice->paid_amount;
        $allocated = (float) $invoice->advance_allocated_amount;
        $totalPaid = $paid + $allocated;

        $status = match(true) {
            $invoice->status === PurchaseInvoiceStatus::Cancelled => $invoice->status,
            $totalPaid <= 0                                         => PurchaseInvoiceStatus::Valid,
            $totalPaid >= $total                                    => PurchaseInvoiceStatus::Paid,
            default                                                 => PurchaseInvoiceStatus::PartialPaid,
        };

        $invoice->update(['status' => $status]);
    }
}
