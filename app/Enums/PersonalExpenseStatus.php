<?php

namespace App\Enums;

enum PersonalExpenseStatus: string
{
    case Draft      = 'draft';
    case Posted     = 'posted';
    case Reimbursed = 'reimbursed';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Nháp',
            self::Posted     => 'Đã ghi sổ',
            self::Reimbursed => 'Đã hoàn tiền',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft      => 'gray',
            self::Posted     => 'blue',
            self::Reimbursed => 'green',
        };
    }
}
