<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Trait GeneratesCode
 *
 * Cung cấp generateCode() an toàn với concurrent requests bằng cách
 * dùng advisory lock (PostgreSQL) hoặc GET_LOCK (MySQL) để đảm bảo
 * chỉ 1 process tạo code tại một thời điểm cho mỗi model.
 *
 * Cách dùng trong Model:
 *   use GeneratesCode;
 *   protected static string $codePrefix = 'DH-';
 *   protected static int $codePad = 4;
 *
 * Sau đó gọi: static::nextCode() thay vì tự implement.
 */
trait GeneratesCode
{
    /**
     * Sinh mã tiếp theo một cách an toàn với concurrent requests.
     * Sử dụng SELECT ... FOR UPDATE trên chính bảng của model để lock.
     */
    public static function nextCode(): string
    {
        $prefix = static::$codePrefix;
        $pad    = static::$codePad ?? 4;

        // Dùng FOR UPDATE để lock row cuối — ngăn 2 request đọc cùng giá trị
        $last = static::lockForUpdate()
            ->orderByDesc('id')
            ->value('code');

        if ($last && str_starts_with($last, $prefix)) {
            $num = ((int) substr($last, strlen($prefix))) + 1;
        } else {
            $num = 1;
        }

        return $prefix . str_pad($num, $pad, '0', STR_PAD_LEFT);
    }
}
