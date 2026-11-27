<?php

namespace App\Models;

use App\Enums\StockEntryStatus;
use App\Models\Concerns\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockEntry extends Model
{
    use GeneratesCode, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'warehouse_id', 'supplier_id', 'entry_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected static string $codePrefix = 'NK-';
    protected static int    $codePad    = 4;

    protected $fillable = [
        'code', 'warehouse_id', 'supplier_id', 'purchase_order_id', 'credit_account_code', 'project_id', 'created_by', 'entry_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockEntryStatus::class,
            'entry_date' => 'date',
        ];
    }

    /** @deprecated Dùng nextCode() thay thế — an toàn hơn với concurrent requests */
    public static function generateCode(): string
    {
        return static::nextCode();
    }

    /** Phiếu nhập thủ công: không có đơn mua, TK Có do người dùng chọn. */
    public function isManual(): bool
    {
        return $this->purchase_order_id === null && $this->credit_account_code !== null;
    }

    /** Phiếu thủ công dạng mua hàng của NCC (Có 331) — được tính VAT, ghi công nợ NCC. */
    public function isManualPurchase(): bool
    {
        return $this->isManual() && str_starts_with((string) $this->credit_account_code, '331');
    }

    /**
     * Phiếu thủ công nhập lại vật tư từ dự án (Có 154 + project_id) — ghi giảm WIP của project_id.
     * Có 154 mà không có dự án = "nhập khác" do kế toán tự chọn TK, không đụng WIP.
     */
    public function isManualProjectReturn(): bool
    {
        return $this->isManual() && $this->project_id !== null
            && str_starts_with((string) $this->credit_account_code, '154');
    }

    /** purchase | project_return | other — null nếu không phải phiếu thủ công. */
    public function manualKind(): ?string
    {
        return match (true) {
            !$this->isManual()             => null,
            $this->isManualPurchase()      => 'purchase',
            $this->isManualProjectReturn() => 'project_return',
            default                        => 'other',
        };
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockEntryItem::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')
            ->where('source_type', self::class);
    }
}
