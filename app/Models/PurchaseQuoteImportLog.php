<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseQuoteImportLog extends Model
{
    protected $fillable = [
        'comparison_id', 'quote_id', 'original_filename', 'file_hash',
        'total_rows', 'valid_rows', 'warning_rows', 'error_rows',
        'import_status', 'imported_by', 'imported_at', 'error_detail_json',
    ];

    protected function casts(): array
    {
        return [
            'imported_at'       => 'datetime',
            'error_detail_json' => 'array',
        ];
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteComparison::class, 'comparison_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseSupplierQuote::class, 'quote_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
