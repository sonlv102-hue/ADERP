<?php

namespace App\Exports\CompanyCashFlow\Concerns;

/**
 * Chống Excel formula injection (spec §22) cho các cột text tự do lấy từ sao kê ngân
 * hàng / user input (nội dung ngân hàng, ghi chú, tên đối tượng, nhãn hợp đồng...).
 * Excel/LibreOffice diễn giải cell bắt đầu bằng =, +, -, @ thành công thức khi mở file.
 */
trait SanitizesFormulaInjection
{
    private function safeText(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Bank statement text đôi khi có whitespace/control char (tab, CR) đứng trước ký tự
        // thật — kiểm tra ký tự non-whitespace ĐẦU TIÊN, không chỉ index 0 của chuỗi gốc.
        $trimmed = ltrim($value, " \t\r\n\0\x0B");

        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)
            ? "'" . $value
            : $value;
    }
}
