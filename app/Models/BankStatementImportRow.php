<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementImportRow extends Model
{
    protected $fillable = [
        'batch_id', 'import_id', 'row_number',
        'transaction_date', 'transaction_no', 'description',
        'counterparty_account_number', 'counterparty_account_name', 'counterparty_bank_name',
        'debit_amount', 'credit_amount', 'balance_after',
        'raw_data_json', 'parse_status', 'error_message',
        'import_hash', 'bank_transaction_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'raw_data_json'    => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BankStatementImportBatch::class, 'batch_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
