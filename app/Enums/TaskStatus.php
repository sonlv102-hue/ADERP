<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo       = 'todo';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Todo       => 'Chưa làm',
            self::InProgress => 'Đang làm',
            self::Done       => 'Hoàn thành',
            self::Cancelled  => 'Đã hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Todo       => 'gray',
            self::InProgress => 'yellow',
            self::Done       => 'green',
            self::Cancelled  => 'red',
        };
    }
}
