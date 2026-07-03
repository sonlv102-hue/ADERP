<?php

namespace App\Models;

use App\Enums\SmallToolStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SmallTool extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['allocation_status', 'paused_at', 'pause_reason', 'resumed_at', 'resumed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'code', 'name', 'category_id', 'unit', 'quantity',
        'original_cost', 'vat_amount', 'total_cost',
        'acquisition_type', 'recognition_method', 'allocation_periods', 'allocation_start_date',
        'purchase_date', 'in_service_date',
        'department', 'responsible_employee_id', 'warehouse_id', 'project_id',
        'supplier_id', 'purchase_invoice_id',
        'payment_type', 'fund_id',
        'stock_account_code', 'pending_account_code', 'expense_account_code', 'payable_account_code',
        'periods_allocated', 'total_allocated',
        'acquisition_journal_entry_id', 'issue_journal_entry_id',
        'status', 'notes', 'created_by', 'updated_by',
        'is_opening_balance', 'opening_balance_period', 'opening_balance_note',
        'allocation_status', 'paused_at', 'paused_by', 'pause_effective_period', 'pause_reason',
        'resumed_at', 'resumed_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date'          => 'date',
            'in_service_date'        => 'date',
            'allocation_start_date'  => 'date',
            'original_cost'          => 'decimal:2',
            'vat_amount'             => 'decimal:2',
            'total_cost'             => 'decimal:2',
            'total_allocated'        => 'decimal:2',
            'status'                 => SmallToolStatus::class,
            'is_opening_balance'     => 'boolean',
            'paused_at'              => 'datetime',
            'resumed_at'             => 'datetime',
        ];
    }

    // -------------------------------------------------------
    // Relations
    // -------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(SmallToolCategory::class, 'category_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function acquisitionJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'acquisition_journal_entry_id');
    }

    public function issueJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'issue_journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SmallToolAllocation::class)->orderBy('period');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(SmallToolTransfer::class)->orderByDesc('transfer_date');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(SmallToolDisposal::class)->orderByDesc('disposal_date');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pausedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }

    // -------------------------------------------------------
    // Computed attributes
    // -------------------------------------------------------

    public function totalRemaining(): Attribute
    {
        return Attribute::get(fn () => max(0, (float) $this->original_cost - (float) $this->total_allocated));
    }

    public function monthlyAllocationAmount(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->allocation_periods || $this->allocation_periods <= 0) return 0;
            return round((float) $this->original_cost / $this->allocation_periods, 2);
        });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    public static function generateCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num  = $last ? ((int) substr($last, 5)) + 1 : 1;
        return 'CCDC-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function isInStock(): bool        { return $this->status === SmallToolStatus::InStock; }
    public function isAllocating(): bool     { return $this->status === SmallToolStatus::Allocating; }
    public function canIssue(): bool         { return $this->status === SmallToolStatus::InStock; }
    public function canCancel(): bool        { return in_array($this->status, [SmallToolStatus::Draft, SmallToolStatus::InStock]); }

    public function isPaused(): bool               { return $this->allocation_status === 'paused'; }
    public function isAllocationCompleted(): bool  { return $this->allocation_status === 'completed'; }
    public function canPauseAllocation(): bool     { return $this->status === SmallToolStatus::Allocating && $this->allocation_status === 'active'; }
    public function canResumeAllocation(): bool    { return $this->allocation_status === 'paused'; }
}
