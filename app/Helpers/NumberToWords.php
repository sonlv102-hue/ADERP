<?php

namespace App\Helpers;

class NumberToWords
{
    private static array $ones = [
        '', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín',
        'mười', 'mười một', 'mười hai', 'mười ba', 'mười bốn', 'mười lăm',
        'mười sáu', 'mười bảy', 'mười tám', 'mười chín',
    ];

    private static array $tens = [
        '', '', 'hai mươi', 'ba mươi', 'bốn mươi', 'năm mươi',
        'sáu mươi', 'bảy mươi', 'tám mươi', 'chín mươi',
    ];

    public static function toVietnamese(int $number): string
    {
        if ($number === 0) return 'Không đồng';

        $result = self::convert($number);
        return ucfirst(trim($result)) . ' đồng';
    }

    private static function convert(int $n): string
    {
        if ($n < 20) return self::$ones[$n];

        if ($n < 100) {
            $ten  = self::$tens[(int)($n / 10)];
            $one  = $n % 10;
            $unit = $one === 1 ? 'mốt' : ($one === 5 ? 'lăm' : (self::$ones[$one] ?? ''));
            return $one === 0 ? $ten : "$ten $unit";
        }

        if ($n < 1000) {
            $h    = (int)($n / 100);
            $rest = $n % 100;
            $base = self::$ones[$h] . ' trăm';
            if ($rest === 0)  return $base;
            if ($rest < 10)   return $base . ' lẻ ' . self::$ones[$rest];
            return $base . ' ' . self::convert($rest);
        }

        if ($n < 1_000_000) {
            $t    = (int)($n / 1000);
            $rest = $n % 1000;
            $base = self::convert($t) . ' nghìn';
            if ($rest === 0) return $base;
            if ($rest < 100) return $base . ' không trăm ' . self::convert($rest);
            return $base . ' ' . self::convert($rest);
        }

        if ($n < 1_000_000_000) {
            $m    = (int)($n / 1_000_000);
            $rest = $n % 1_000_000;
            $base = self::convert($m) . ' triệu';
            if ($rest === 0) return $base;
            if ($rest < 100_000) return $base . ' không trăm ' . self::convert($rest);
            return $base . ' ' . self::convert($rest);
        }

        $b    = (int)($n / 1_000_000_000);
        $rest = $n % 1_000_000_000;
        $base = self::convert($b) . ' tỷ';
        if ($rest === 0) return $base;
        return $base . ' ' . self::convert($rest);
    }
}
