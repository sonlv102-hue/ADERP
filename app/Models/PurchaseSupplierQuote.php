<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseSupplierQuote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'comparison_id', 'supplier_id', 'quote_no', 'quote_date', 'valid_until',
        'currency', 'payment_terms', 'shipping_fee', 'note', 'version_no',
        'is_active_version', 'original_filename', 'stored_file_path', 'file_hash',
        'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'quote_date'        => 'date',
            'valid_until'       => 'date',
            'shipping_fee'      => 'decimal:2',
            'version_no'        => 'integer',
            'is_active_version' => 'boolean',
            'uploaded_at'       => 'datetime',
        ];
    }

    public function scopeActiveVersion(Builder $query): Builder
    {
        return $query->where('is_active_version', true);
    }

    public function comparison(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteComparison::class, 'comparison_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseSupplierQuoteLine::class, 'quote_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return array{subtotal:float, vat:float, total:float} — excludes shipping_fee */
    public function lineTotals(): array
    {
        $subtotal = 0.0;
        $vat = 0.0;
        foreach ($this->lines as $line) {
            $qty = (float) ($line->comparisonItem->requested_qty ?? 0);
            $lineSub = $qty * (float) $line->net_unit_price;
            $subtotal += $lineSub;
            $vat += $lineSub * (float) $line->vat_percent / 100;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'vat'      => round($vat, 2),
            'total'    => round($subtotal + $vat, 2),
        ];
    }
}
