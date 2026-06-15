<?php

namespace App\Services;

use App\Enums\FundTransferStatus;
use App\Models\AccountCode;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FundTransferService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Ghi sổ phiếu luân chuyển quỹ.
     * Bút toán:
     *   Dr to_fund.account_code  / Cr from_fund.account_code  (số tiền)
     */
    public function post(FundTransfer $transfer): void
    {
        if ($transfer->status !== FundTransferStatus::Draft) {
            throw new RuntimeException('Chỉ ghi sổ phiếu ở trạng thái nháp.');
        }
        if ((float) $transfer->amount <= 0) {
            throw new RuntimeException('Số tiền luân chuyển phải lớn hơn 0.');
        }
        if ($transfer->from_fund_id === $transfer->to_fund_id) {
            throw new RuntimeException('Quỹ nguồn và quỹ đích không được trùng nhau.');
        }

        // Chống double-post
        $exists = JournalEntry::where('reference_type', 'fund_transfer')
            ->where('reference_id', $transfer->id)
            ->whereIn('status', ['posted', 'draft'])
            ->exists();
        if ($exists) {
            throw new RuntimeException('Phiếu đã được ghi sổ trước đó.');
        }

        $transfer->loadMissing('fromFund', 'toFund');
        $from = $transfer->fromFund;
        $to   = $transfer->toFund;

        $fromAccount = $this->resolveFundAccount($from);
        $toAccount   = $this->resolveFundAccount($to);

        $this->assertDetailAccount($fromAccount, 'Quỹ nguồn');
        $this->assertDetailAccount($toAccount,   'Quỹ đích');

        $amount  = (int) round((float) $transfer->amount);
        $desc    = $transfer->description ?: "Luân chuyển quỹ từ {$from->name} sang {$to->name}";

        DB::transaction(function () use ($transfer, $fromAccount, $toAccount, $amount, $desc) {
            $je = $this->accounting->post(
                description:       $desc,
                date:              Carbon::parse($transfer->transfer_date),
                lines:             [
                    ['account' => $toAccount,   'debit' => $amount, 'credit' => 0,      'description' => $desc],
                    ['account' => $fromAccount, 'debit' => 0,       'credit' => $amount, 'description' => $desc],
                ],
                referenceType:     'fund_transfer',
                referenceId:       $transfer->id,
                isAuto:            false,
                journalSourceType: 'fund_transfer',
            );

            $transfer->update([
                'status'          => FundTransferStatus::Posted,
                'journal_entry_id'=> $je->id,
                'posted_by'       => auth()->id(),
                'posted_at'       => now(),
            ]);
        });

        activity()
            ->causedBy(auth()->user())
            ->performedOn($transfer)
            ->withProperties(['amount' => $transfer->amount])
            ->log('posted');
    }

    /**
     * Đảo phiếu luân chuyển quỹ đã ghi sổ.
     */
    public function reverse(FundTransfer $transfer, string $reason = ''): void
    {
        if ($transfer->status !== FundTransferStatus::Posted) {
            throw new RuntimeException('Chỉ đảo phiếu đã ghi sổ.');
        }

        DB::transaction(function () use ($transfer, $reason) {
            $this->accounting->reverseOrDelete(
                'fund_transfer',
                $transfer->id,
                "Đảo luân chuyển quỹ {$transfer->transfer_no}" . ($reason ? ": {$reason}" : '')
            );

            $transfer->update([
                'status'      => FundTransferStatus::Reversed,
                'reversed_by' => auth()->id(),
            ]);
        });

        activity()
            ->causedBy(auth()->user())
            ->performedOn($transfer)
            ->withProperties(['reason' => $reason])
            ->log('reversed');
    }

    /**
     * Hủy phiếu nháp.
     */
    public function cancel(FundTransfer $transfer): void
    {
        if ($transfer->status !== FundTransferStatus::Draft) {
            throw new RuntimeException('Chỉ có thể hủy phiếu nháp. Phiếu đã ghi sổ cần đảo trước.');
        }

        $transfer->update(['status' => FundTransferStatus::Cancelled]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($transfer)
            ->log('cancelled');
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Lấy TK kế toán của quỹ.
     * Ưu tiên fund.account_code (chi tiết); fallback theo type.
     */
    private function resolveFundAccount(\App\Models\Fund $fund): string
    {
        if (!empty($fund->account_code)) {
            return $fund->account_code;
        }
        return $fund->type === 'bank'
            ? AccountingSettings::get('bank_account', '1121')
            : AccountingSettings::get('cash_account', '1111');
    }

    private function assertDetailAccount(string $code, string $label): void
    {
        $acc = AccountCode::where('code', $code)->first();
        if (!$acc) {
            throw new RuntimeException("Tài khoản '{$code}' ({$label}) không tồn tại trong hệ thống.");
        }
        if (!$acc->is_detail) {
            throw new RuntimeException(
                "TK {$code} ({$label}) là tài khoản tổng hợp — không thể hạch toán. "
                . "Vui lòng cấu hình tài khoản chi tiết cho quỹ."
            );
        }
    }
}
