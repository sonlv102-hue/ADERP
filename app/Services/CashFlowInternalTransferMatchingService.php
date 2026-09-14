<?php

namespace App\Services;

use App\Models\BankTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cặp đôi 2 giao dịch chuyển khoản nội bộ (VCB→BIDV) để dòng tiền thuần TOÀN
 * CÔNG TY = 0 (spec §5/§6/§13). Chỉ GỢI Ý — kế toán phải confirm thủ công,
 * không tự động 100%.
 */
class CashFlowInternalTransferMatchingService
{
    private const DEFAULT_DAY_WINDOW = 3;

    /**
     * @return array<int, array{outgoing: BankTransaction, candidates: array<int, BankTransaction>, ambiguous: bool, confidence: int}>
     */
    public function suggestPairs(int $dayWindow = self::DEFAULT_DAY_WINDOW): array
    {
        $outgoing = BankTransaction::query()
            ->whereNull('paired_transaction_id')
            ->whereNotNull('internal_account_id')
            ->where('debit', '>', 0)
            ->get();

        $incomingPool = BankTransaction::query()
            ->whereNull('paired_transaction_id')
            ->whereNotNull('internal_account_id')
            ->where('credit', '>', 0)
            ->get();

        $suggestions = [];
        foreach ($outgoing as $out) {
            $candidates = $incomingPool->filter(function (BankTransaction $in) use ($out, $dayWindow) {
                if ($in->bank_account_id === $out->bank_account_id) {
                    return false; // phải khác tài khoản mới là chuyển khoản nội bộ
                }
                if (bccomp((string) $in->credit, (string) $out->debit, 0) !== 0) {
                    return false;
                }
                return abs(Carbon::parse($in->transaction_date)->diffInDays($out->transaction_date)) <= $dayWindow;
            })->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            // Nhiều giao dịch đối ứng cùng số tiền/khớp ngày -> KHÔNG được tự chọn bừa
            // một cái rồi auto-confirm (spec §5 Case B). Giữ nguyên trạng thái "gợi ý
            // nhiều ứng viên", để kế toán tự chọn đúng cái qua confirmPair().
            $ambiguous = $candidates->count() > 1;

            $suggestions[] = [
                'outgoing'   => $out,
                'candidates' => $candidates->all(),
                'ambiguous'  => $ambiguous,
                'confidence' => $ambiguous ? 40 : 80,
            ];

            if (!$ambiguous) {
                $incomingPool = $incomingPool->reject(fn ($i) => $i->id === $candidates->first()->id)->values();
            }
        }

        return $suggestions;
    }

    public function confirmPair(BankTransaction $a, BankTransaction $b): void
    {
        if ($a->id === $b->id) {
            throw new RuntimeException('Không thể cặp đôi một giao dịch với chính nó.');
        }
        if ($a->paired_transaction_id || $b->paired_transaction_id) {
            throw new RuntimeException('Một trong hai giao dịch đã được cặp đôi.');
        }
        if (!$a->internal_account_id || !$b->internal_account_id) {
            throw new RuntimeException('Chỉ được cặp đôi các giao dịch đã xác định là tài khoản nội bộ công ty (spec §5 Case C).');
        }
        if ($a->bank_account_id === $b->bank_account_id) {
            throw new RuntimeException('Hai giao dịch cặp đôi phải thuộc hai tài khoản ngân hàng khác nhau.');
        }

        DB::transaction(function () use ($a, $b) {
            $a->update(['paired_transaction_id' => $b->id]);
            $b->update(['paired_transaction_id' => $a->id]);
        });

        activity()->performedOn($a)
            ->withProperties(['action' => 'PAIR_INTERNAL_TRANSFER', 'paired_with' => $b->id])
            ->log('pair_internal_transfer');
    }

    public function unpair(BankTransaction $tx): void
    {
        $pairId = $tx->paired_transaction_id;
        if (!$pairId) {
            return;
        }

        DB::transaction(function () use ($tx, $pairId) {
            $tx->update(['paired_transaction_id' => null]);
            BankTransaction::whereKey($pairId)->update(['paired_transaction_id' => null]);
        });

        activity()->performedOn($tx)
            ->withProperties(['action' => 'UNPAIR_INTERNAL_TRANSFER', 'was_paired_with' => $pairId])
            ->log('unpair_internal_transfer');
    }
}
