<?php

namespace App\Models;

use App\Enums\PurchaseQuoteComparisonStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseQuoteComparison extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'comparison_date', 'department', 'project_id',
        'buyer_id', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status'          => PurchaseQuoteComparisonStatus::class,
            'comparison_date' => 'date',
        ];
    }

    public static function generateCode(): string
    {
        $prefix = 'SSBG-' . now()->year . '-';
        $last = static::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->value('code');
        $num = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $num, 5, '0', STR_PAD_LEFT);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseQuoteComparisonItem::class, 'comparison_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(PurchaseSupplierQuote::class, 'comparison_id');
    }

    public function activeQuotes(): HasMany
    {
        return $this->quotes()->where('is_active_version', true);
    }

    public function selections(): HasManyThrough
    {
        return $this->hasManyThrough(
            PurchaseQuoteSelection::class,
            PurchaseQuoteComparisonItem::class,
            'comparison_id',      // FK on comparison_items
            'comparison_item_id', // FK on selections
            'id',
            'id'
        );
    }

    public function importLogs(): HasMany
    {
        return $this->hasMany(PurchaseQuoteImportLog::class, 'comparison_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
