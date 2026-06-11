<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PitConfig extends Model
{
    protected $fillable = [
        'effective_from',
        'effective_to',
        'personal_deduction',
        'dependent_deduction',
        'insurance_cap',
        'brackets',
        'legal_basis',
        'is_active',
    ];

    protected $casts = [
        'effective_from'      => 'date',
        'effective_to'        => 'date',
        'personal_deduction'  => 'integer',
        'dependent_deduction' => 'integer',
        'insurance_cap'       => 'integer',
        'brackets'            => 'array',
        'is_active'           => 'boolean',
    ];

    /**
     * Lấy cấu hình PIT hiệu lực tại ngày $date.
     * Ưu tiên record có effective_from gần nhất (mới nhất).
     *
     * @throws \RuntimeException nếu không có cấu hình nào hiệu lực
     */
    public static function forDate(Carbon $date): self
    {
        $config = static::where('is_active', true)
            ->where('effective_from', '<=', $date->format('Y-m-d'))
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $date->format('Y-m-d'));
            })
            ->orderByDesc('effective_from')
            ->first();

        if (! $config) {
            throw new \RuntimeException(
                "Không tìm thấy cấu hình thuế TNCN hiệu lực tại ngày {$date->format('d/m/Y')}. " .
                "Vui lòng vào Quản trị → Cấu hình thuế TNCN để thêm mới."
            );
        }

        return $config;
    }

    /**
     * Trả về biểu thuế lũy tiến.
     * Nếu không có brackets riêng → dùng biểu cố định 7 bậc theo Luật số 04/2007/QH12.
     */
    public function getBrackets(): array
    {
        if (! empty($this->brackets)) {
            return $this->brackets;
        }

        return [
            [5_000_000,  5],
            [10_000_000, 10],
            [18_000_000, 15],
            [32_000_000, 20],
            [52_000_000, 25],
            [80_000_000, 30],
            [null,        35],
        ];
    }
}
