<?php

namespace App\Models;

use App\Enums\FixedAssetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category', 'category_id',
        'asset_type', 'serial_number', 'source_type',
        'supplier_id', 'purchase_invoice_id', 'invoice_date',
        'acquisition_date', 'recognition_date', 'placed_in_service_date',
        'depreciation_start_date', 'depreciation_end_date',
        'acquisition_cost', 'vat_amount', 'total_amount',
        'depreciable_amount', 'opening_accumulated_depreciation',
        'useful_life_months', 'depreciation_method',
        'accumulated_depreciation', 'last_depreciation_period',
        'original_cost_account_code', 'accumulated_dep_account_code',
        'depreciation_expense_account_code', 'payable_account_code',
        'acquisition_journal_entry_id',
        'location', 'department', 'responsible_user', 'usage_purpose',
        'is_for_business', 'is_sedan_under_9_seats', 'tax_deductible_cost',
        'status', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date'                   => 'date',
            'recognition_date'                   => 'date',
            'invoice_date'                       => 'date',
            'placed_in_service_date'             => 'date',
            'depreciation_start_date'            => 'date',
            'depreciation_end_date'              => 'date',
            'acquisition_cost'                   => 'decimal:2',
            'vat_amount'                         => 'decimal:2',
            'total_amount'                       => 'decimal:2',
            'depreciable_amount'                 => 'decimal:2',
            'opening_accumulated_depreciation'   => 'decimal:2',
            'accumulated_depreciation'           => 'decimal:2',
            'tax_deductible_cost'                => 'decimal:2',
            'is_for_business'                    => 'boolean',
            'is_sedan_under_9_seats'             => 'boolean',
            'status'                             => FixedAssetStatus::class,
        ];
    }

    // -------------------------------------------------------
    // Relations
    // -------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function acquisitionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'acquisition_journal_entry_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FixedAssetMovement::class)->orderByDesc('movement_date');
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(FixedAssetRepair::class)->orderByDesc('repair_date');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class)->orderByDesc('disposal_date');
    }

    // -------------------------------------------------------
    // Computed attributes
    // -------------------------------------------------------

    public function monthlyDepreciation(): Attribute
    {
        return Attribute::get(function () {
            $base = (float) $this->depreciable_amount ?: (float) $this->acquisition_cost;
            return $this->useful_life_months > 0
                ? round($base / $this->useful_life_months, 2)
                : 0;
        });
    }

    public function netBookValue(): Attribute
    {
        return Attribute::get(fn () => max(0, (float) $this->acquisition_cost - (float) $this->accumulated_depreciation));
    }

    public function depreciationRate(): Attribute
    {
        return Attribute::get(fn () => $this->useful_life_months > 0
            ? round(12 / $this->useful_life_months * 100, 2)
            : 0);
    }

    /**
     * Phần khấu hao được trừ thuế TNDN mỗi tháng.
     * Với xe ≤9 chỗ: dựa trên tax_deductible_cost (≤1,6 tỷ).
     */
    public function taxDeductibleMonthlyDepreciation(): Attribute
    {
        return Attribute::get(function () {
            $base = (float) ($this->tax_deductible_cost ?? $this->depreciable_amount ?: $this->acquisition_cost);
            return $this->useful_life_months > 0 ? round($base / $this->useful_life_months, 2) : 0;
        });
    }

    /**
     * Phần khấu hao không được trừ thuế TNDN mỗi tháng (xe ≤9 chỗ vượt 1,6 tỷ).
     */
    public function monthlyNonDeductibleDepreciation(): Attribute
    {
        return Attribute::get(function () {
            return max(0, round($this->monthly_depreciation - $this->tax_deductible_monthly_depreciation, 2));
        });
    }

    public function monthsDepreciated(): Attribute
    {
        return Attribute::get(fn () => $this->depreciations()->where('status', 'posted')->count());
    }

    public function monthsRemaining(): Attribute
    {
        return Attribute::get(function () {
            $posted = $this->depreciations()->where('status', 'posted')->count();
            return max(0, $this->useful_life_months - $posted);
        });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    public static function generateCode(): string
    {
        $last = static::withTrashed()->orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'TSCĐ-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function getAssetAccountCode(): string
    {
        if ($this->original_cost_account_code) return $this->original_cost_account_code;
        return match($this->asset_type) {
            'finance_lease' => '2112',
            'intangible'    => '2113',
            default         => '2111',
        };
    }

    public function getDepreciationAccountCode(): string
    {
        if ($this->accumulated_dep_account_code) return $this->accumulated_dep_account_code;
        return match($this->asset_type) {
            'finance_lease' => '2142',
            'intangible'    => '2143',
            default         => '2141',
        };
    }
}
