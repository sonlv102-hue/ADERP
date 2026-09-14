<?php

namespace App\Enums;

enum QuoteSelectionReason: string
{
    case FasterDelivery = 'faster_delivery';
    case BetterPaymentTerms = 'better_payment_terms';
    case BetterQuality = 'better_quality';
    case InStock = 'in_stock';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FasterDelivery => 'Giao hàng nhanh hơn',
            self::BetterPaymentTerms => 'Điều khoản thanh toán tốt hơn',
            self::BetterQuality => 'Chất lượng tốt hơn',
            self::InStock => 'Có sẵn hàng',
            self::Other => 'Khác',
        };
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases()
        );
    }
}
