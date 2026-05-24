<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Labor     = 'labor';
    case Transport = 'transport';
    case Material  = 'material';
    case Other     = 'other';

    public function label(): string
    {
        return match($this) {
            self::Labor     => 'Nhân công',
            self::Transport => 'Đi lại',
            self::Material  => 'Vật tư phát sinh',
            self::Other     => 'Khác',
        };
    }
}
