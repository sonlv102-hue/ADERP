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

    /** TK mặc định bên Nợ theo danh mục — kế toán kết chuyển về 154 sau */
    public function defaultDebitAccount(): string
    {
        return match($this) {
            self::Labor     => '6271',
            self::Equipment => '6237',
            self::Material  => '6272',
            self::Transport => '6278',
            self::Other     => '6279',
        };
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
