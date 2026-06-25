<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    protected $fillable = [
        'batch_id', 'bank_account_id', 'source_type', 'original_filename', 'file_path',
        'detected_bank_name', 'detected_account_number',
        'statement_from_date', 'statement_to_date',
        'total_rows_detected', 'total_rows_valid', 'total_rows_duplicate', 'total_rows_error',
        'status', 'error_message',
    ];

    protected $casts = [
        'statement_from_date' => 'date',
        'statement_to_date'   => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BankStatementImportBatch::class, 'batch_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BankStatementImportRow::class, 'import_id');
    }
}
