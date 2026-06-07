<?php

namespace App\Models;

use App\Enums\AccountingPostingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPostingJob extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'posting_type',
        'status', 'journal_entry_id',
        'posting_date', 'description', 'lines',
        'error_code', 'error_message',
        'attempts', 'last_attempted_at', 'posted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'            => AccountingPostingStatus::class,
            'lines'             => 'array',
            'posting_date'      => 'date',
            'last_attempted_at' => 'datetime',
            'posted_at'         => 'datetime',
            'attempts'          => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function sourceTypeLabel(string $type): string
    {
        return match($type) {
            'invoice'                   => 'Hóa đơn',
            'payment'                   => 'Thanh toán hóa đơn',
            'cash_voucher'              => 'Phiếu thu/chi',
            'prepaid_expense'           => 'Chi phí trả trước',
            'prepaid_expense_allocation'=> 'Phân bổ CPTTT',
            'purchase_invoice_payment'  => 'Thanh toán NCC',
            'stock_entry'               => 'Phiếu nhập kho',
            'stock_exit'                => 'Phiếu xuất kho',
            'payroll'                   => 'Bảng lương',
            default                     => $type,
        };
    }
}
