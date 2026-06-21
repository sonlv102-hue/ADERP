<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Labor     = 'labor';
    case Equipment = 'equipment';
    case Material  = 'material';
    case Transport = 'transport';
    case Other     = 'other';

    public function label(): string
    {
        return match($this) {
            self::Labor     => 'Nhân công',
            self::Equipment => 'Máy thi công',
            self::Material  => 'Vật tư phát sinh',
            self::Transport => 'Vận chuyển',
            self::Other     => 'Khác',
        };
    }

    /** TK mặc định bên Nợ — từ 2026-06-21 mặc định là 154 (hạch toán thẳng vào WIP dự án) */
    public function defaultDebitAccount(): string
    {
        return '154';
    }

    /** cost_type cho ProjectWipEntry */
    public function wipCostType(): string
    {
        return match($this) {
            self::Labor    => 'labor',
            self::Material => 'material',
            default        => 'overhead',
        };
    }
}
