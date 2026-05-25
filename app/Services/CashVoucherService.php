<?php

namespace App\Services;

use App\Enums\CashVoucherStatus;
use App\Models\CashVoucher;
use RuntimeException;

class CashVoucherService
{
    public function confirm(CashVoucher $voucher): void
    {
        if ($voucher->status !== CashVoucherStatus::Draft) {
            throw new RuntimeException('Chỉ có thể xác nhận phiếu ở trạng thái nháp.');
        }

        if ($voucher->amount <= 0) {
            throw new RuntimeException('Số tiền phải lớn hơn 0.');
        }

        $voucher->update(['status' => CashVoucherStatus::Confirmed]);
    }

    public function cancel(CashVoucher $voucher): void
    {
        if ($voucher->status === CashVoucherStatus::Cancelled) {
            throw new RuntimeException('Phiếu đã bị hủy trước đó.');
        }

        $voucher->update(['status' => CashVoucherStatus::Cancelled]);
    }
}
