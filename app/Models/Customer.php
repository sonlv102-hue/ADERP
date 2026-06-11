<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone', 'email', 'tax_code', 'address'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'code', 'name', 'company', 'tax_code', 'phone', 'email',
        'address', 'lead_status', 'assigned_to', 'notes',
        'payment_term_id', 'credit_limit', 'is_fdi',
        'receivable_account_code',
    ];

    protected function casts(): array
    {
        return [
            'lead_status'  => LeadStatus::class,
            'credit_limit' => 'decimal:0',
            'is_fdi'       => 'boolean',
        ];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 3)) + 1 : 1;
        return 'KH-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(AccountCode::class, 'receivable_account_code', 'code');
    }

    public function getReceivableAccount(): string
    {
        if (!$this->receivable_account_code) {
            throw new \RuntimeException(
                "Khách hàng \"{$this->name}\" chưa cấu hình tài khoản phải thu. "
                . 'Vào Danh mục → Khách hàng → Sửa để chọn tài khoản.'
            );
        }
        return $this->receivable_account_code;
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact()
    {
        return $this->contacts()->where('is_primary', true)->first();
    }
}
