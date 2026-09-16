<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ghi nhớ quan hệ "số TK ngân hàng đối ứng -> đối tượng + danh mục dòng tiền",
 * học từ thao tác xác nhận/sửa của Admin (BankTransactionClassificationSuggestionService::learn()).
 * Thuần dữ liệu quản trị dòng tiền — không liên quan Journal Entry/kế toán.
 */
class BankCounterpartyMapping extends Model
{
    protected $fillable = [
        'bank_account_number', 'bank_name', 'party_type', 'party_id', 'party_name',
        'cash_flow_category_id', 'confidence', 'created_by',
    ];

    public function cashFlowCategory(): BelongsTo
    {
        return $this->belongsTo(CashFlowCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function normalizeAccountNumber(string $accountNumber): string
    {
        return preg_replace('/[\s\-\.]/', '', $accountNumber);
    }
}
