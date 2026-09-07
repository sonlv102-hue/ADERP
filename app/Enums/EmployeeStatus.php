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

    /** Đang có quan hệ lao động (dùng cho lọc nghiệp vụ lương/chấm công). */
    public function isWorking(): bool
    {
        return in_array($this, [self::Active, self::Probation], true);
    }

    /** @return array<int, string> */
    public static function workingValues(): array
    {
        return [self::Active->value, self::Probation->value];
    }

    /** @return array<int, string> — trạng thái đã kết thúc quan hệ lao động */
    public static function endedValues(): array
    {
        return [self::Resigned->value, self::Terminated->value];
    }
}
