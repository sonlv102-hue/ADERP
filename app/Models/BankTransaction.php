<?php

namespace App\Models;

use App\Enums\BankTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'status'           => BankTransactionStatus::class,
            'transaction_date' => 'date',
            'value_date'       => 'date',
            'reconciled_at'    => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
