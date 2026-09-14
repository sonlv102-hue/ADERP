<?php

namespace App\Models;

use App\Enums\BankTransactionMatchStatus;
use App\Enums\BankTransactionStatus;
use App\Enums\CashFlowReconcileStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    protected $fillable = [
        'bank_account_id', 'transaction_date', 'value_date',
        'description', 'reference', 'debit', 'credit', 'running_balance',
        'counterpart_bank', 'counterpart_account', 'counterpart_name',
        'tx_type', 'supplier_bank_account_id', 'internal_account_id', 'alert_note',
        'internal_status', 'internal_note', 'return_amount',
        'status', 'journal_entry_id', 'reconciled_at', 'reconciled_by',
        'import_batch', 'import_hash', 'created_by',
        // Matching workflow
        'match_status', 'matched_party_type', 'matched_party_id',
        'matched_document_type', 'matched_document_id', 'confidence_score',
        'match_note', 'suggested_tx_type', 'reconcile_mode',
        'customer_bank_account_id', 'cash_voucher_id', 'confirmed_by', 'confirmed_at',
        // Báo cáo dòng tiền tài khoản công ty — chỉ ghi qua CashFlowClassificationService
        'cash_flow_category_id', 'project_id', 'contract_type', 'contract_id',
        'party_type', 'party_id', 'party_name', 'responsible_user_id',
        'cash_flow_note', 'paired_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'status'           => BankTransactionStatus::class,
            'match_status'     => BankTransactionMatchStatus::class,
            'transaction_date' => 'date',
            'value_date'       => 'date',
            'reconciled_at'    => 'datetime',
            'confirmed_at'     => 'datetime',
            'debit'            => 'decimal:0',
            'credit'           => 'decimal:0',
            'running_balance'  => 'decimal:0',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function supplierBankAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierBankAccount::class);
    }

    public function internalAccount(): BelongsTo
    {
        return $this->belongsTo(InternalBankAccount::class, 'internal_account_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function txTypeLabel(): string
    {
        return match($this->tx_type) {
            'supplier_payment'  => 'Thanh toán NCC',
            'internal_transfer' => 'Chuyển khoản nội bộ',
            'customer_receipt'  => 'Thu từ khách hàng',
            'other'             => 'Khác',
            default             => 'Chưa phân loại',
        };
    }

    public function txTypeColor(): string
    {
        return match($this->tx_type) {
            'supplier_payment'  => 'orange',
            'internal_transfer' => 'purple',
            'customer_receipt'  => 'green',
            'other'             => 'gray',
            default             => 'slate',
        };
    }

    public function internalStatusLabel(): string
    {
        return match($this->internal_status) {
            'docs_done'     => 'Đã có hồ sơ',
            'needs_return'  => 'Cần hoàn ứng',
            'returned'      => 'Đã hoàn ứng',
            default         => 'Chưa xử lý',
        };
    }

    public function internalStatusColor(): string
    {
        return match($this->internal_status) {
            'docs_done'    => 'blue',
            'needs_return' => 'red',
            'returned'     => 'green',
            default        => 'amber',
        };
    }

    public function customerBankAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerBankAccount::class, 'customer_bank_account_id');
    }

    public function cashVoucher(): BelongsTo
    {
        return $this->belongsTo(CashVoucher::class, 'cash_voucher_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BankTransactionAllocation::class);
    }

    public function matchedPartyName(): ?string
    {
        if ($this->matched_party_type === 'customer') {
            return \App\Models\Customer::find($this->matched_party_id)?->name;
        }
        if ($this->matched_party_type === 'supplier') {
            return \App\Models\Supplier::find($this->matched_party_id)?->name;
        }
        return null;
    }

    // ── Báo cáo dòng tiền tài khoản công ty ─────────────────────────────────

    public function cashFlowCategory(): BelongsTo
    {
        return $this->belongsTo(CashFlowCategory::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function pairedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'paired_transaction_id');
    }

    public function partyModel(): ?Model
    {
        return match ($this->party_type) {
            'customer'    => Customer::find($this->party_id),
            'supplier'    => Supplier::find($this->party_id),
            'employee'    => Employee::find($this->party_id),
            'shareholder' => Shareholder::find($this->party_id),
            default       => null,
        };
    }

    public function contractLabel(): ?string
    {
        $contract = match ($this->contract_type) {
            'contract'          => Contract::find($this->contract_id),
            'purchase_contract' => PurchaseContract::find($this->contract_id),
            default             => null,
        };

        return $contract ? "{$contract->code} — {$contract->title}" : null;
    }

    /**
     * Business rule cho từng trạng thái đối soát (nguồn chuẩn — SQL filter ở
     * CompanyCashFlowReportService::applyReconcileStatusFilter() PHẢI cho cùng kết quả,
     * xem test cross-check CompanyCashFlowFilterAndAuthTest):
     *
     *  1. Unclassified    — chưa có party VÀ chưa có category.
     *  2. NeedsReview      — đã phân loại category = "chuyển tiền nội bộ" nhưng CHƯA
     *                        cặp đôi được giao dịch đối ứng (paired_transaction_id null).
     *                        Ưu tiên cao hơn các nhánh dưới vì đây là vấn đề cần kế toán
     *                        xử lý dù các field khác đã đầy đủ.
     *  3. Completed        — có ĐỦ party + category + document (cash_voucher/matched
     *                        document/contract) VÀ không rơi vào case 2.
     *  4. DocumentLinked   — có document nhưng thiếu 1 trong 2 (party hoặc category).
     *  5. Categorized      — có category, KHÔNG có document.
     *  6. PartyIdentified  — có party, KHÔNG có category, KHÔNG có document (else).
     */
    public function reconcileStatus(): CashFlowReconcileStatus
    {
        if ($this->party_type === null && $this->cash_flow_category_id === null) {
            return CashFlowReconcileStatus::Unclassified;
        }
        if ($this->paired_transaction_id === null && $this->cashFlowCategory?->isInternalTransfer()) {
            return CashFlowReconcileStatus::NeedsReview;
        }
        $hasDocument = $this->cash_voucher_id !== null
            || $this->matched_document_id !== null
            || $this->contract_id !== null;
        if ($this->party_type !== null && $this->cash_flow_category_id !== null && $hasDocument) {
            return CashFlowReconcileStatus::Completed;
        }
        if ($hasDocument) {
            return CashFlowReconcileStatus::DocumentLinked;
        }
        if ($this->cash_flow_category_id !== null) {
            return CashFlowReconcileStatus::Categorized;
        }
        return CashFlowReconcileStatus::PartyIdentified;
    }

    public function scopeForPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('transaction_date', [$from, $to]);
    }

    /**
     * Loại giao dịch chuyển khoản nội bộ khỏi dòng tiền thuần TOÀN CÔNG TY (spec §5/§6).
     * Loại theo 2 điều kiện ĐỘC LẬP nhau — không chỉ dựa vào pairing:
     *  - đã cặp đôi (paired_transaction_id set), HOẶC
     *  - đã được phân loại category = chuyển tiền nội bộ (dù chưa/không cặp đôi được,
     *    ví dụ counterpart chưa import) — spec §6: classification quyết định treatment
     *    trong báo cáo, không phụ thuộc tuyệt đối vào việc đã tìm được cặp hay chưa.
     */
    public function scopeExcludingInternalTransfers(Builder $query): Builder
    {
        $internalCategoryIds = CashFlowCategory::query()
            ->whereIn('code', CashFlowCategory::INTERNAL_TRANSFER_CODES)
            ->pluck('id');

        return $query->whereNull('paired_transaction_id')
            ->when($internalCategoryIds->isNotEmpty(), fn (Builder $q) => $q->where(function (Builder $b) use ($internalCategoryIds) {
                // whereNotIn loại luôn cả NULL (SQL 3-value logic) — phải giữ rõ NULL lại.
                $b->whereNull('cash_flow_category_id')->orWhereNotIn('cash_flow_category_id', $internalCategoryIds);
            }));
    }
}
