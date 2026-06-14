<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Quản lý TK kế toán cấu hình được — thay thế các hardcode trong services.
 * Đọc từ bảng accounting_settings với cache request-level.
 * Fallback về default nếu chưa cấu hình.
 */
class AccountingSettings
{
    protected static array $cache = [];

    public static function get(string $key, string $default = ''): string
    {
        if (! array_key_exists($key, static::$cache)) {
            static::$cache[$key] = DB::table('accounting_settings')
                ->where('key', $key)
                ->value('value') ?? $default;
        }

        return static::$cache[$key] ?: $default;
    }

    /**
     * Lấy nhiều keys một lần (batch load để giảm queries).
     */
    public static function many(array $keys): array
    {
        $missing = array_filter($keys, fn ($k) => ! array_key_exists($k, static::$cache));

        if ($missing) {
            $rows = DB::table('accounting_settings')
                ->whereIn('key', $missing)
                ->pluck('value', 'key');

            foreach ($missing as $k) {
                static::$cache[$k] = $rows[$k] ?? null;
            }
        }

        return array_map(fn ($k) => static::$cache[$k] ?? '', $keys);
    }

    public static function clearCache(): void
    {
        static::$cache = [];
    }
}
