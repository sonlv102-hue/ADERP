<?php

namespace App\Enums;

enum SerialStatus: string
{
    case InStock             = 'in_stock';
    case Sold                = 'sold';
    case InService           = 'in_service';
    case Warranty            = 'warranty';
    case Retired             = 'retired';
    case Cancelled           = 'cancelled';
    case ReturnedToSupplier  = 'returned_to_supplier';

    public function label(): string
    {
        return match($this) {
            self::InStock            => 'Trong kho',
            self::Sold               => 'Đã bán',
            self::InService          => 'Đang sửa chữa',
            self::Warranty           => 'Bảo hành',
            self::Retired            => 'Ngừng sử dụng',
            self::Cancelled          => 'Đã hủy nhập',
            self::ReturnedToSupplier => 'Đã trả NCC',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::InStock            => 'green',
            self::Sold               => 'blue',
            self::InService          => 'yellow',
            self::Warranty           => 'orange',
            self::Retired            => 'gray',
            self::Cancelled          => 'red',
            self::ReturnedToSupplier => 'purple',
        };
    }

    public function allowedTransitions(): array
    {
        return match($this) {
            self::InStock            => [self::Sold, self::InService, self::Retired, self::Cancelled, self::ReturnedToSupplier],
            self::Sold               => [self::Warranty, self::InStock],
            self::InService          => [self::InStock, self::Retired],
            self::Warranty           => [self::InStock, self::Retired],
            self::Retired            => [],
            self::Cancelled          => [],
            self::ReturnedToSupplier => [self::InStock],
        };
    }
}
