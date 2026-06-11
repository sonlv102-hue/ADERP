<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'tax_code', 'phone', 'email', 'address',
        'bank_name', 'bank_account', 'bank_account_name', 'bank_branch',
        'notes', 'is_active', 'payment_term_id', 'payable_account_code',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function generateCode(): string
    {
        $last = self::withTrashed()->orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 4)) + 1 : 1;
        return 'NCC-' . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Trả về mã TK công nợ phải trả chi tiết (3311/3312/3318...).
     * Ném RuntimeException nếu NCC chưa cấu hình — không fallback ngầm về 331.
     */
    public function getPayableAccount(): string
    {
        if (!$this->payable_account_code) {
            throw new \RuntimeException(
                "Nhà cung cấp \"{$this->name}\" chưa cấu hình tài khoản công nợ phải trả chi tiết. "
                . 'Vào Danh mục → Nhà cung cấp → Sửa để chọn tài khoản.'
            );
        }
        return $this->payable_account_code;
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(AccountCode::class, 'payable_account_code', 'code');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function stockEntries(): HasMany
    {
        return $this->hasMany(StockEntry::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class);
    }

    public function primaryBankAccount()
    {
        return $this->bankAccounts()->where('is_primary', true)->first()
            ?? $this->bankAccounts()->where('is_active', true)->first();
    }
}
