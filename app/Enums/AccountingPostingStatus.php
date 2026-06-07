<?php

namespace App\Enums;

enum AccountingPostingStatus: string
{
    case Pending = 'pending';
    case Posted  = 'posted';
    case Failed  = 'failed';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'Chờ hạch toán',
            self::Posted  => 'Đã hạch toán',
            self::Failed  => 'Lỗi',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'yellow',
            self::Posted  => 'green',
            self::Failed  => 'red',
        };
    }
}
