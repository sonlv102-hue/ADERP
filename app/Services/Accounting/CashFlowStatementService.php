<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * B03-DNN — Báo cáo lưu chuyển tiền tệ (phương pháp trực tiếp).
 * Phân loại các giao dịch tiền mặt/TGNH theo TK đối ứng.
 */
class CashFlowStatementService
{
    // TK tiền: 111x (tiền mặt) + 112x (tiền gửi ngân hàng)
    private const CASH_PREFIXES = ['111', '112'];

    /**
     * Mapping [direction => [tk_prefix => [section, line_code, label]]].
     * direction: 'in' = Dr cash, 'out' = Cr cash.
     */
    private const MAP = [
        'in' => [
            '131'  => ['operating', '01', 'Thu tiền từ bán hàng và CCDV'],
            '5111' => ['operating', '01', 'Thu tiền từ bán hàng và CCDV'],
            '5113' => ['operating', '01', 'Thu tiền từ bán hàng và CCDV'],
            '511'  => ['operating', '01', 'Thu tiền từ bán hàng và CCDV'],
            '521'  => ['operating', '06', 'Thu tiền khác từ HĐKD'],
            '515'  => ['operating', '06', 'Thu tiền khác từ HĐKD'],
            '711'  => ['operating', '06', 'Thu tiền khác từ HĐKD'],
            '133'  => ['operating', '06', 'Thu tiền khác từ HĐKD'],
            '311'  => ['financing', '33', 'Tiền vay ngắn hạn, dài hạn nhận được'],
            '341'  => ['financing', '33', 'Tiền vay ngắn hạn, dài hạn nhận được'],
            '342'  => ['financing', '33', 'Tiền vay ngắn hạn, dài hạn nhận được'],
            '411'  => ['financing', '31', 'Tiền thu từ phát hành CP, nhận vốn góp CSH'],
        ],
        'out' => [
            '331'  => ['operating', '02', 'Tiền chi trả người cung cấp hàng hóa, dịch vụ'],
            '156'  => ['operating', '02', 'Tiền chi trả người cung cấp hàng hóa, dịch vụ'],
            '334'  => ['operating', '03', 'Tiền chi trả cho người lao động'],
            '3341' => ['operating', '03', 'Tiền chi trả cho người lao động'],
            '333'  => ['operating', '05', 'Tiền nộp thuế và các khoản nộp khác vào NSNN'],
            '3331' => ['operating', '05', 'Tiền nộp thuế và các khoản nộp khác vào NSNN'],
            '3334' => ['operating', '05', 'Tiền nộp thuế và các khoản nộp khác vào NSNN'],
            '3335' => ['operating', '05', 'Tiền nộp thuế và các khoản nộp khác vào NSNN'],
            '338'  => ['operating', '05', 'Tiền nộp thuế và các khoản nộp khác vào NSNN'],
            '635'  => ['operating', '04', 'Tiền chi trả lãi vay'],
            '642'  => ['operating', '07', 'Tiền chi khác cho HĐKD'],
            '6421' => ['operating', '07', 'Tiền chi khác cho HĐKD'],
            '6422' => ['operating', '07', 'Tiền chi khác cho HĐKD'],
            '811'  => ['operating', '07', 'Tiền chi khác cho HĐKD'],
            '211'  => ['investing', '21', 'Tiền chi mua sắm, xây dựng TSCĐ và ĐT dài hạn'],
            '213'  => ['investing', '21', 'Tiền chi mua sắm, xây dựng TSCĐ và ĐT dài hạn'],
            '217'  => ['investing', '21', 'Tiền chi mua sắm, xây dựng TSCĐ và ĐT dài hạn'],
            '241'  => ['investing', '21', 'Tiền chi mua sắm, xây dựng TSCĐ và ĐT dài hạn'],
            '311'  => ['financing', '34', 'Tiền chi trả nợ gốc vay'],
            '341'  => ['financing', '34', 'Tiền chi trả nợ gốc vay'],
            '342'  => ['financing', '34', 'Tiền chi trả nợ gốc vay'],
            '421'  => ['financing', '36', 'Cổ tức, lợi nhuận đã trả cho CSH'],
            '4211' => ['financing', '36', 'Cổ tức, lợi nhuận đã trả cho CSH'],
            '4212' => ['financing', '36', 'Cổ tức, lợi nhuận đã trả cho CSH'],
            '411'  => ['financing', '32', 'Tiền chi trả vốn góp cho CSH, mua lại CP'],
        ],
    ];

    public function build(string $from, string $to): array
    {
        $cashAccounts = $this->resolveCashAccounts();

        $openingCash = $this->cashBalance($cashAccounts, $from);
        $movements   = $this->getCashMovements($cashAccounts, $from, $to);
        $counterpartsByJe = $this->getCounterparts($movements->pluck('je_id')->unique(), $cashAccounts);

        $totals = $this->initTotals();
        foreach ($movements as $line) {
            $amount    = (float) $line->debit - (float) $line->credit; // positive=in, negative=out
            $direction = $amount >= 0 ? 'in' : 'out';
            $counterparts = $counterpartsByJe[$line->je_id] ?? collect();
            $code = $this->classify($direction, $counterparts);
            $totals[$code] += $amount;
        }

        return $this->buildOutput($from, $to, $openingCash, $totals);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function resolveCashAccounts(): array
    {
        $all = DB::table('account_codes')->where('is_active', true)->pluck('code')->toArray();
        return array_values(array_filter($all, function ($code) {
            foreach (self::CASH_PREFIXES as $prefix) {
                if (str_starts_with((string) $code, $prefix)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function cashBalance(array $cashAccounts, string $before): float
    {
        if (empty($cashAccounts)) {
            return 0.0;
        }
        $result = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<', $before)
            ->whereIn('jel.account_code', $cashAccounts)
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as dr, COALESCE(SUM(jel.credit), 0) as cr')
            ->first();

        return (float)($result?->dr ?? 0) - (float)($result?->cr ?? 0);
    }

    private function getCashMovements(array $cashAccounts, string $from, string $to)
    {
        if (empty($cashAccounts)) {
            return collect();
        }
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->whereIn('jel.account_code', $cashAccounts)
            ->select('je.id as je_id', 'jel.account_code', 'jel.debit', 'jel.credit')
            ->get();
    }

    private function getCounterparts(\Illuminate\Support\Collection $jeIds, array $cashAccounts)
    {
        if ($jeIds->isEmpty()) {
            return collect();
        }
        return DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $jeIds)
            ->whereNotIn('account_code', $cashAccounts)
            ->select('journal_entry_id', 'account_code',
                DB::raw('COALESCE(SUM(debit),0) as dr, COALESCE(SUM(credit),0) as cr'))
            ->groupBy('journal_entry_id', 'account_code')
            ->get()
            ->groupBy('journal_entry_id');
    }

    private function classify(string $direction, \Illuminate\Support\Collection $counterparts): string
    {
        $map = self::MAP[$direction] ?? [];

        // Find dominant counterpart by amount
        $dominant = $counterparts->sortByDesc(fn($l) => (float)$l->dr + (float)$l->cr)->first();
        if (!$dominant) {
            return $direction === 'in' ? '06' : '07';
        }

        $code = (string) $dominant->account_code;

        // Try increasingly shorter prefixes until match found
        for ($len = min(strlen($code), 4); $len >= 1; $len--) {
            $prefix = substr($code, 0, $len);
            if (isset($map[$prefix])) {
                return $map[$prefix][1]; // line_code
            }
        }

        return $direction === 'in' ? '06' : '07'; // default
    }

    private function initTotals(): array
    {
        // All possible line codes — positive = inflow, negative = outflow
        return [
            '01' => 0.0, '02' => 0.0, '03' => 0.0, '04' => 0.0,
            '05' => 0.0, '06' => 0.0, '07' => 0.0,
            '21' => 0.0, '22' => 0.0,
            '31' => 0.0, '32' => 0.0, '33' => 0.0, '34' => 0.0, '36' => 0.0,
        ];
    }

    private function buildOutput(string $from, string $to, float $opening, array $totals): array
    {
        $netOperating = $totals['01'] + $totals['02'] + $totals['03'] + $totals['04']
                      + $totals['05'] + $totals['06'] + $totals['07'];
        $netInvesting = $totals['21'] + $totals['22'];
        $netFinancing = $totals['31'] + $totals['32'] + $totals['33'] + $totals['34'] + $totals['36'];
        $netTotal     = $netOperating + $netInvesting + $netFinancing;
        $closing      = $opening + $netTotal;

        return [
            'from'         => $from,
            'to'           => $to,
            'opening_cash' => $opening,
            'closing_cash' => $closing,
            'net_total'    => $netTotal,
            'sections'     => [
                [
                    'code'  => 'I',
                    'label' => 'LƯU CHUYỂN TIỀN TỪ HOẠT ĐỘNG KINH DOANH',
                    'net'   => $netOperating,
                    'net_code' => '20',
                    'lines' => [
                        ['code' => '01', 'label' => 'Thu tiền từ bán hàng, cung cấp dịch vụ và DT khác', 'amount' => $totals['01']],
                        ['code' => '02', 'label' => 'Tiền chi trả cho người cung cấp hàng hóa, dịch vụ', 'amount' => $totals['02']],
                        ['code' => '03', 'label' => 'Tiền chi trả cho người lao động',                   'amount' => $totals['03']],
                        ['code' => '04', 'label' => 'Tiền chi trả lãi vay',                              'amount' => $totals['04']],
                        ['code' => '05', 'label' => 'Tiền nộp thuế và các khoản nộp khác vào NSNN',      'amount' => $totals['05']],
                        ['code' => '06', 'label' => 'Tiền thu khác từ HĐKD',                             'amount' => $totals['06']],
                        ['code' => '07', 'label' => 'Tiền chi khác cho HĐKD',                            'amount' => $totals['07']],
                    ],
                ],
                [
                    'code'  => 'II',
                    'label' => 'LƯU CHUYỂN TIỀN TỪ HOẠT ĐỘNG ĐẦU TƯ',
                    'net'   => $netInvesting,
                    'net_code' => '30',
                    'lines' => [
                        ['code' => '21', 'label' => 'Tiền chi mua sắm, xây dựng TSCĐ và đầu tư dài hạn',          'amount' => $totals['21']],
                        ['code' => '22', 'label' => 'Tiền thu từ thanh lý, nhượng bán TSCĐ và ĐT dài hạn khác',   'amount' => $totals['22']],
                    ],
                ],
                [
                    'code'  => 'III',
                    'label' => 'LƯU CHUYỂN TIỀN TỪ HOẠT ĐỘNG TÀI CHÍNH',
                    'net'   => $netFinancing,
                    'net_code' => '40',
                    'lines' => [
                        ['code' => '31', 'label' => 'Tiền thu từ phát hành CP, nhận vốn góp CSH',      'amount' => $totals['31']],
                        ['code' => '32', 'label' => 'Tiền chi trả vốn góp cho CSH, mua lại CP',        'amount' => $totals['32']],
                        ['code' => '33', 'label' => 'Tiền vay ngắn hạn, dài hạn nhận được',             'amount' => $totals['33']],
                        ['code' => '34', 'label' => 'Tiền chi trả nợ gốc vay',                          'amount' => $totals['34']],
                        ['code' => '36', 'label' => 'Cổ tức, lợi nhuận đã trả cho chủ sở hữu',         'amount' => $totals['36']],
                    ],
                ],
            ],
        ];
    }
}
