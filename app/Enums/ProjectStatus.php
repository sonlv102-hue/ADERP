<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning   = 'planning';
    case InProgress = 'in_progress';
    case OnHold     = 'on_hold';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Planning   => 'Lên kế hoạch',
            self::InProgress => 'Đang thi công',
            self::OnHold     => 'Tạm dừng',
            self::Completed  => 'Hoàn thành',
            self::Cancelled  => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Planning   => 'blue',
            self::InProgress => 'yellow',
            self::OnHold     => 'orange',
            self::Completed  => 'green',
            self::Cancelled  => 'red',
        };
    }
}
