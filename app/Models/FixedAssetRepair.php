<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetRepair extends Model
{
    protected $fillable = [
        'fixed_asset_id', 'repair_type', 'repair_date', 'description',
        'amount', 'vat_amount', 'supplier_id', 'purchase_invoice_id',
        'accounting_treatment', 'allocation_months',
        'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'repair_date' => 'date',
            'amount'      => 'decimal:2',
            'vat_amount'  => 'decimal:2',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repairTypeLabel(): string
    {
        return match($this->repair_type) {
            'regular'              => 'Sửa chữa thường xuyên',
            'major_repair'         => 'Sửa chữa lớn',
            'upgrade'              => 'Nâng cấp/Cải tạo',
            default                => $this->repair_type,
        };
    }

    public function treatmentLabel(): string
    {
        return match($this->accounting_treatment) {
            'expense_now'          => 'Ghi vào chi phí ngay',
            'prepaid_allocation'   => 'Phân bổ qua 242',
            'increase_original_cost' => 'Tăng nguyên giá qua 241',
            default                => $this->accounting_treatment,
        };
    }
}
