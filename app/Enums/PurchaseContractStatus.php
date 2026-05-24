<?php

namespace App\Enums;

enum PurchaseContractStatus: string
{
    case Draft      = 'draft';
    case Active     = 'active';
    case Completed  = 'completed';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match($this) {
            self::Draft      => 'Nháp',
            self::Active     => 'Hiệu lực',
            self::Completed  => 'Hoàn thành',
            self::Terminated => 'Chấm dứt',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft      => 'gray',
            self::Active     => 'green',
            self::Completed  => 'blue',
            self::Terminated => 'red',
        };
    }
}
