<?php

namespace App\Enums;

enum BankTransactionMatchStatus: string
{
    case Unmatched       = 'unmatched';
    case Suggested       = 'suggested';
    case Confirmed       = 'confirmed';
    case PartiallyMatched = 'partially_matched';
    case Matched         = 'matched';
    case Posted          = 'posted';
    case Ignored         = 'ignored';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Unmatched        => 'Chưa đối chiếu',
            self::Suggested        => 'Có đề xuất',
            self::Confirmed        => 'Đã xác nhận',
            self::PartiallyMatched => 'Đối chiếu một phần',
            self::Matched          => 'Đã đối chiếu',
            self::Posted           => 'Đã hạch toán',
            self::Ignored          => 'Bỏ qua',
            self::Cancelled        => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Unmatched        => 'slate',
            self::Suggested        => 'yellow',
            self::Confirmed        => 'blue',
            self::PartiallyMatched => 'yellow',
            self::Matched          => 'blue',
            self::Posted           => 'green',
            self::Ignored          => 'gray',
            self::Cancelled        => 'red',
        };
    }
}
