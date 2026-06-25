<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImportBatch extends Model
{
    protected $fillable = [
        'bank_account_id', 'source_type', 'total_files',
        'total_rows_detected', 'total_rows_valid', 'total_rows_duplicate', 'total_rows_error',
        'status', 'uploaded_by', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class, 'batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BankStatementImportRow::class, 'batch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
