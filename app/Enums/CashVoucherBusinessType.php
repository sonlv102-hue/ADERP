<?php

namespace App\Enums;

enum CashVoucherBusinessType: string
{
    case AdvancePayment  = 'advance_payment';    // Chi tạm ứng
    case AdvanceReturn   = 'advance_return';     // Thu hoàn ứng
    case CollectOffset   = 'collect_offset';     // Thu tiền đối ứng cá nhân
    case PayOffset       = 'pay_offset';         // Chi hoàn trả đối ứng
    case PaySupplier     = 'pay_supplier';       // Chi trả nhà cung cấp
    case CollectCustomer = 'collect_customer';   // Thu tiền khách hàng
    case ExpensePayment  = 'expense_payment';    // Chi phí bằng tiền

    public function label(): string
    {
        return match ($this) {
            self::AdvancePayment  => 'Chi tạm ứng',
            self::AdvanceReturn   => 'Thu hoàn ứng',
            self::CollectOffset   => 'Thu tiền đối ứng cá nhân',
            self::PayOffset       => 'Chi hoàn trả đối ứng',
            self::PaySupplier     => 'Chi trả nhà cung cấp',
            self::CollectCustomer => 'Thu tiền khách hàng',
            self::ExpensePayment  => 'Chi phí bằng tiền',
        };
    }

    /** Loại phiếu tương ứng (receipt | payment) */
    public function voucherType(): string
    {
        return match ($this) {
            self::AdvancePayment, self::PayOffset, self::PaySupplier, self::ExpensePayment => 'payment',
            self::AdvanceReturn, self::CollectOffset, self::CollectCustomer               => 'receipt',
        };
    }

    /** Tất cả nghiệp vụ cho một loại phiếu */
    public static function forVoucherType(string $type): array
    {
        return array_values(array_filter(
            self::cases(),
            fn ($case) => $case->voucherType() === $type
        ));
    }

    /** Tài khoản đối ứng mặc định (phía không phải tiền) */
    public function defaultCounterAccount(): string
    {
        return match ($this) {
            self::AdvancePayment, self::AdvanceReturn   => '141',
            self::CollectOffset, self::PayOffset         => '3388',
            self::PaySupplier                            => '3311',
            self::CollectCustomer                        => '1311',
            self::ExpensePayment                         => '6422',
        };
    }

    /** Partner type mặc định cho nghiệp vụ này */
    public function defaultPartnerType(): ?string
    {
        return match ($this) {
            self::AdvancePayment, self::AdvanceReturn  => 'employee',
            self::PaySupplier                          => 'supplier',
            self::CollectCustomer                      => 'customer',
            default                                    => null,
        };
    }
}
