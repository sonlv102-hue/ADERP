<?php

namespace App\Services;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Tạo và post một bút toán kép.
     *
     * $lines = [
     *   ['account' => '131', 'debit' => 5000000, 'credit' => 0, 'description' => '...'],
     *   ['account' => '511', 'debit' => 0, 'credit' => 4545455, 'description' => '...'],
     *   ['account' => '3331', 'debit' => 0, 'credit' => 454545, 'description' => '...'],
     * ]
     */
    public function post(
        string $description,
        CarbonInterface $date,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        bool $isAuto = false,
        ?string $notes = null
    ): JournalEntry {
        $this->validateLines($lines);
        $this->checkPeriodOpen($date);

        return DB::transaction(function () use ($description, $date, $lines, $referenceType, $referenceId, $isAuto, $notes) {
            $entry = JournalEntry::create([
                'code'           => JournalEntry::generateCode(),
                'entry_date'     => $date,
                'description'    => $description,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'status'         => 'posted',
                'is_auto'        => $isAuto,
                'created_by'     => auth()->id() ?? 1,
                'posted_at'      => now(),
                'notes'          => $notes,
            ]);

            foreach ($lines as $i => $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_code'     => $line['account'],
                    'description'      => $line['description'] ?? null,
                    'debit'            => (int) ($line['debit'] ?? 0),
                    'credit'           => (int) ($line['credit'] ?? 0),
                    'sort_order'       => $i,
                    'project_id'       => $line['project_id'] ?? null,
                ]);
            }

            // Mở kỳ kế toán nếu chưa tồn tại
            AccountingPeriod::findOrCreateForDate($date);

            return $entry;
        });
    }

    /** Đảo bút toán (tạo bút toán ngược) */
    public function reverse(JournalEntry $entry, ?string $reason = null): JournalEntry
    {
        if ($entry->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể đảo bút toán đã hạch toán.');
        }

        $entry->load('lines');
        $reversedLines = $entry->lines->map(fn ($l) => [
            'account'     => $l->account_code,
            'debit'       => (int) $l->credit,
            'credit'      => (int) $l->debit,
            'description' => $l->description,
            'project_id'  => $l->project_id,
        ])->all();

        $reversal = $this->post(
            description: 'Đảo: ' . $entry->description,
            date: now(),
            lines: $reversedLines,
            referenceType: $entry->reference_type,
            referenceId: $entry->reference_id,
            isAuto: $entry->is_auto,
            notes: $reason,
        );

        $entry->update(['status' => 'reversed', 'reversed_by_id' => $reversal->id]);

        return $reversal;
    }

    /**
     * Số dư tài khoản trong khoảng thời gian.
     * Trả về: ['debit' => float, 'credit' => float, 'balance' => float]
     */
    public function getAccountBalance(string $accountCode, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = JournalEntryLine::where('account_code', $accountCode)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'));

        if ($from) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '>=', $from));
        }
        if ($to) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $to));
        }

        $debit  = (float) $query->sum('debit');
        $credit = (float) $query->sum('credit');

        $account = AccountCode::find($accountCode);
        $balance = $account
            ? ($account->normal_balance === 'debit' ? $debit - $credit : $credit - $debit)
            : $debit - $credit;

        return compact('debit', 'credit', 'balance');
    }

    /**
     * Số dư nhiều tài khoản cùng lúc (tránh N+1).
     * Trả về: ['111' => ['debit'=>..., 'credit'=>..., 'balance'=>...], ...]
     */
    public function getMultipleBalances(array $accountCodes, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = JournalEntryLine::whereIn('account_code', $accountCodes)
            ->whereHas('entry', fn ($q) => $q->where('status', 'posted'))
            ->select('account_code', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'))
            ->groupBy('account_code');

        if ($from) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '>=', $from));
        }
        if ($to) {
            $query->whereHas('entry', fn ($q) => $q->where('entry_date', '<=', $to));
        }

        $accounts = AccountCode::whereIn('code', $accountCodes)->get()->keyBy('code');
        $rows = $query->get()->keyBy('account_code');

        $result = [];
        foreach ($accountCodes as $code) {
            $row    = $rows->get($code);
            $acc    = $accounts->get($code);
            $debit  = (float) ($row?->total_debit ?? 0);
            $credit = (float) ($row?->total_credit ?? 0);
            $balance = $acc
                ? ($acc->normal_balance === 'debit' ? $debit - $credit : $credit - $debit)
                : $debit - $credit;
            $result[$code] = compact('debit', 'credit', 'balance');
        }

        return $result;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('Bút toán phải có ít nhất 2 dòng.');
        }

        $totalDebit  = array_sum(array_column($lines, 'debit'));
        $totalCredit = array_sum(array_column($lines, 'credit'));

        if (abs($totalDebit - $totalCredit) >= 1) {
            throw new \InvalidArgumentException(
                "Bút toán không cân: Nợ={$totalDebit}, Có={$totalCredit}."
            );
        }
    }

    private function checkPeriodOpen(CarbonInterface $date): void
    {
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if ($period && $period->status !== 'open') {
            throw new \RuntimeException(
                "Kỳ kế toán {$period->label()} đã đóng/khóa. Không thể hạch toán."
            );
        }
    }
}
