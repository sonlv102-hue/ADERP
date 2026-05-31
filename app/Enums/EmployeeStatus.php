<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active      = 'active';
    case Probation   = 'probation';
    case Resigned    = 'resigned';
    case Terminated  = 'terminated';

    public function label(): string
    {
        return match($this) {
            self::Active     => 'Đang làm',
            self::Probation  => 'Thử việc',
            self::Resigned   => 'Đã nghỉ',
            self::Terminated => 'Chấm dứt HĐ',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Active     => 'green',
            self::Probation  => 'yellow',
            self::Resigned   => 'gray',
            self::Terminated => 'red',
        };
    }
}
