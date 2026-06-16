<?php

namespace App\Enums;

use App\Services\AccountingSettings;

enum CashVoucherBusinessType: string
{
    case AdvancePayment             = 'advance_payment';             // Chi tạm ứng → Dr 141
    case AdvanceReturn              = 'advance_return';              // Thu hoàn ứng → Cr 141
    case CollectOffset              = 'collect_offset';              // Thu tiền chi hộ (hoàn 3388 về CT)
    case PayOffset                  = 'pay_offset';                  // Chi hoàn tiền chi hộ cho cá nhân (Dr 3388)
    case PaySupplier                = 'pay_supplier';                // Chi trả nhà cung cấp
    case CollectCustomer            = 'collect_customer';            // Thu tiền khách hàng
    case ExpensePayment             = 'expense_payment';             // Chi phí bằng tiền
    case EquityContribution         = 'equity_contribution';         // Nhận góp vốn → Cr 4111
    case CollectPersonalReceivable  = 'collect_personal_receivable'; // Thu hồi phải thu cá nhân → Cr 1388
    case SalaryPayment              = 'salary_payment';              // Chi lương → Dr 3341

    public function label(): string
    {
        return match ($this) {
            self::AdvancePayment            => 'Chi tạm ứng',
            self::AdvanceReturn             => 'Thu hoàn ứng',
            self::CollectOffset             => 'Thu tiền chi hộ (hoàn 3388)',
            self::PayOffset                 => 'Hoàn tiền chi hộ cho cá nhân',
            self::PaySupplier               => 'Chi trả nhà cung cấp',
            self::CollectCustomer           => 'Thu tiền khách hàng',
            self::ExpensePayment            => 'Chi phí bằng tiền',
            self::EquityContribution        => 'Nhận góp vốn chủ sở hữu',
            self::CollectPersonalReceivable => 'Thu hồi phải thu cá nhân',
            self::SalaryPayment             => 'Thanh toán lương',
        };
    }

    /** Loại phiếu tương ứng (receipt | payment) */
    public function voucherType(): string
    {
        return match ($this) {
            self::AdvancePayment, self::PayOffset, self::PaySupplier,
            self::ExpensePayment, self::SalaryPayment                                     => 'payment',
            self::AdvanceReturn, self::CollectOffset, self::CollectCustomer,
            self::EquityContribution, self::CollectPersonalReceivable                     => 'receipt',
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
            self::EquityContribution                     => '4111',
            self::CollectPersonalReceivable              => '1388',
            self::SalaryPayment                          => AccountingSettings::get('salary_payable_account', '3341'),
        };
    }

    /** Partner type mặc định cho nghiệp vụ này */
    public function defaultPartnerType(): ?string
    {
        return match ($this) {
            self::AdvancePayment, self::AdvanceReturn,
            self::CollectPersonalReceivable,
            self::SalaryPayment                          => 'employee',
            self::PaySupplier                            => 'supplier',
            self::CollectCustomer                        => 'customer',
            self::EquityContribution                     => 'shareholder',
            default                                      => null,
        };
    }
}
