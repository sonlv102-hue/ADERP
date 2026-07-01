<?php

namespace App\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B03-DNN — Báo cáo lưu chuyển tiền tệ (phương pháp trực tiếp, TT133/2016).
 * Nguồn dữ liệu: posted journal_entries chạm TK 111/112.
 * cash_flow_code trên cash_vouchers là override thủ công khi auto-classify sai.
 */
class CashFlowStatementService
{
    private const CASH_PREFIXES = ['111', '112'];

    // direction(in|out) => account_prefix => cash_flow_code
    private const MAP = [
        'in' => [
            '131'  => '01', '511'  => '01', '5111' => '01', '5113' => '01',
            '521'  => '01', // chiết khấu thương mại (giảm trừ doanh thu)
            '515'  => '06', // lãi tiền gửi → khác HĐKD (nếu không phân biệt)
            '711'  => '06', // thu nhập khác
            '133'  => '06', // hoàn thuế GTGT
            '211'  => '22', '213' => '22', '217' => '22', // thanh lý TSCĐ
            '128'  => '24', '228' => '24', '136' => '24', // thu hồi cho vay
            '121'  => '25', // cổ tức nhận được
            '311'  => '33', '341' => '33', '342' => '33', // vay tiền
            '411'  => '31', // nhận vốn góp
        ],
        'out' => [
            '331'  => '02', '3311' => '02', '3312' => '02', '156' => '02',
            '334'  => '03', '3341' => '03', // trả lương
            '635'  => '04', // lãi vay
            '333'  => '05', '3334' => '05', '3335' => '05', // thuế TNDN
            '642'  => '07', '6421' => '07', '6422' => '07', '811' => '07',
            '338'  => '07', '33821' => '07', // chi phí khác
            '211'  => '21', '213' => '21', '217' => '21', '241' => '21',
            '128'  => '23', '228' => '23', '136' => '23', // cho vay, đầu tư
            '311'  => '34', '341' => '34', '342' => '34', // trả nợ gốc
            '421'  => '35', '4211' => '35', '4212' => '35', // trả cổ tức
            '411'  => '32', // trả lại vốn góp
        ],
    ];

    // B03-DNN line structure (code => [label, section, isSummary, sumOf])
    public const LINES = [
        '01' => ['Tiền thu từ bán hàng, cung cấp dịch vụ và doanh thu khác',         'I',   false, null],
        '02' => ['Tiền chi trả cho người cung cấp hàng hóa, dịch vụ',                  'I',   false, null],
        '03' => ['Tiền chi trả cho người lao động',                                     'I',   false, null],
        '04' => ['Tiền lãi vay đã trả',                                                 'I',   false, null],
        '05' => ['Thuế thu nhập doanh nghiệp đã nộp',                                   'I',   false, null],
        '06' => ['Tiền thu khác từ hoạt động kinh doanh',                               'I',   false, null],
        '07' => ['Tiền chi khác cho hoạt động kinh doanh',                              'I',   false, null],
        '20' => ['Lưu chuyển tiền thuần từ hoạt động kinh doanh (20=01+02+03+04+05+06+07)', 'I', true, ['01','02','03','04','05','06','07']],
        '21' => ['Tiền chi để mua sắm, xây dựng TSCĐ, BĐSĐT và các tài sản dài hạn khác', 'II', false, null],
        '22' => ['Tiền thu từ thanh lý, nhượng bán TSCĐ, BĐSĐT và các tài sản dài hạn khác', 'II', false, null],
        '23' => ['Tiền chi cho vay, mua các công cụ nợ của đơn vị khác',                'II',  false, null],
        '24' => ['Tiền thu hồi cho vay, bán lại các công cụ nợ của đơn vị khác',        'II',  false, null],
        '25' => ['Tiền thu lãi cho vay, cổ tức và lợi nhuận được chia',                 'II',  false, null],
        '30' => ['Lưu chuyển tiền thuần từ hoạt động đầu tư (30=21+22+23+24+25)',       'II',  true, ['21','22','23','24','25']],
        '31' => ['Tiền thu từ phát hành cổ phiếu, nhận vốn góp của chủ sở hữu',        'III', false, null],
        '32' => ['Tiền chi trả vốn góp cho các chủ sở hữu, mua lại cổ phiếu đã phát hành', 'III', false, null],
        '33' => ['Tiền vay ngắn hạn, dài hạn nhận được',                                'III', false, null],
        '34' => ['Tiền chi trả nợ gốc vay và nợ thuê tài chính',                        'III', false, null],
        '35' => ['Cổ tức, lợi nhuận đã trả cho chủ sở hữu',                            'III', false, null],
        '40' => ['Lưu chuyển tiền thuần từ hoạt động tài chính (40=31+32+33+34+35)',    'III', true, ['31','32','33','34','35']],
        '50' => ['Lưu chuyển tiền thuần trong kỳ (50=20+30+40)',                         null,  true, ['20','30','40']],
        '60' => ['Tiền và tương đương tiền đầu kỳ',                                      null,  true, null],
        '61' => ['Ảnh hưởng của thay đổi tỷ giá hối đoái quy đổi ngoại tệ',             null,  false, null],
        '70' => ['Tiền và tương đương tiền cuối kỳ (70=50+60+61)',                       null,  true, ['50','60','61']],
    ];

    public function getReport(int $year, string $unit = 'dong'): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to   = Carbon::create($year, 12, 31)->endOfDay();

        return $this->getReportForRange($from, $to, $unit, [
            'type'      => 'year',
            'date_from' => $from->toDateString(),
            'date_to'   => $to->toDateString(),
            'label'     => "Năm {$year}",
        ], [
            'date_from' => Carbon::create($year - 1, 1, 1)->toDateString(),
            'date_to'   => Carbon::create($year - 1, 12, 31)->toDateString(),
            'label'     => 'Cùng kỳ năm trước',
        ]);
    }

    /**
     * Bản tổng quát của getReport() — nhận trực tiếp khoảng ngày thay vì cả năm.
     * getReport(int $year) delegate về đây. $comparisonPeriod=null → không so sánh (prev=0).
     */
    public function getReportForRange(
        Carbon $from,
        Carbon $to,
        string $unit,
        array $period,
        ?array $comparisonPeriod = null,
    ): array {
        $divisor = match ($unit) {
            'nghin_dong'  => 1000,
            'trieu_dong'  => 1000000,
            default       => 1,
        };

        $currAmounts = $this->computeAllLines($from, $to);
        $prevAmounts = $comparisonPeriod
            ? $this->computeAllLines(
                Carbon::parse($comparisonPeriod['date_from'])->startOfDay(),
                Carbon::parse($comparisonPeriod['date_to'])->endOfDay()
            )
            : array_fill_keys(array_keys(self::LINES), 0.0);

        $currBeg = $this->getBeginningCashBalanceAsOf($from);
        $prevBeg = $comparisonPeriod
            ? $this->getBeginningCashBalanceAsOf(Carbon::parse($comparisonPeriod['date_from'])->startOfDay())
            : 0.0;

        $currAmounts['60'] = $currBeg;
        $prevAmounts['60'] = $prevBeg;
        $currAmounts['61'] = 0.0; // tỷ giá chưa quản lý
        $prevAmounts['61'] = 0.0;

        // Compute summary lines
        foreach (self::LINES as $code => $def) {
            if ($def[2] && $def[3]) { // isSummary && has sumOf
                $currAmounts[$code] = array_sum(array_map(fn ($c) => $currAmounts[$c] ?? 0.0, $def[3]));
                $prevAmounts[$code] = array_sum(array_map(fn ($c) => $prevAmounts[$c] ?? 0.0, $def[3]));
            }
        }

        $currEnding = $this->getEndingCashBalanceAsOf($to);
        $prevEnding = $comparisonPeriod
            ? $this->getEndingCashBalanceAsOf(Carbon::parse($comparisonPeriod['date_to'])->endOfDay())
            : 0.0;

        $rows = [];
        foreach (self::LINES as $code => $def) {
            $curr = ($currAmounts[$code] ?? 0.0) / $divisor;
            $prev = ($prevAmounts[$code] ?? 0.0) / $divisor;
            $rows[] = [
                'code'       => $code,
                'label'      => $def[0],
                'section'    => $def[1],
                'is_summary' => $def[2],
                'curr'       => round($curr),
                'prev'       => round($prev),
                'note'       => null, // Thuyết minh — để kế toán điền
            ];
        }

        $reconciliation = $this->validateReconciliationForRange($to, $currAmounts['70'] ?? 0.0);

        return [
            'year'              => (int) $to->format('Y'),
            'unit'              => $unit,
            'rows'              => $rows,
            'curr_ending'       => round($currEnding / $divisor),
            'prev_ending'       => round($prevEnding / $divisor),
            'reconciliation'    => $reconciliation,
            'period'            => $period,
            'comparison_period' => $comparisonPeriod,
        ];
    }

    public function getLineAmount(string $code, Carbon $from, Carbon $to): float
    {
        $cashAccounts = $this->resolveCashAccounts();
        if (empty($cashAccounts)) {
            return 0.0;
        }

        $movements = $this->getCashMovements($cashAccounts, $from, $to);
        $counterpartsByJe = $this->getCounterparts($movements->pluck('je_id')->unique(), $cashAccounts);

        $total = 0.0;
        foreach ($movements as $line) {
            $amount    = (float) $line->debit - (float) $line->credit;
            $direction = $amount >= 0 ? 'in' : 'out';
            $counterparts = $counterpartsByJe[$line->je_id] ?? collect();
            $classified = $this->classify($direction, $counterparts);
            if ($classified === $code) {
                $total += $amount;
            }
        }
        return $total;
    }

    public function getBeginningCashBalance(int $year): float
    {
        return $this->getBeginningCashBalanceAsOf(Carbon::create($year, 1, 1)->startOfDay());
    }

    /**
     * Bản tổng quát của getBeginningCashBalance() — nhận trực tiếp mốc ngày bắt đầu kỳ.
     */
    public function getBeginningCashBalanceAsOf(Carbon $before): float
    {
        $cashAccounts = $this->resolveCashAccounts();
        if (empty($cashAccounts)) {
            return 0.0;
        }
        $result = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<', $before->toDateTimeString())
            ->whereIn('jel.account_code', $cashAccounts)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as dr, COALESCE(SUM(jel.credit),0) as cr')
            ->first();
        return (float)($result?->dr ?? 0) - (float)($result?->cr ?? 0);
    }

    public function getEndingCashBalance(int $year): float
    {
        return $this->getEndingCashBalanceAsOf(Carbon::create($year, 12, 31)->endOfDay());
    }

    /**
     * Bản tổng quát của getEndingCashBalance() — nhận trực tiếp mốc ngày cuối kỳ.
     */
    public function getEndingCashBalanceAsOf(Carbon $through): float
    {
        $cashAccounts = $this->resolveCashAccounts();
        if (empty($cashAccounts)) {
            return 0.0;
        }
        $result = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $through->toDateTimeString())
            ->whereIn('jel.account_code', $cashAccounts)
            ->selectRaw('COALESCE(SUM(jel.debit),0) as dr, COALESCE(SUM(jel.credit),0) as cr')
            ->first();
        return (float)($result?->dr ?? 0) - (float)($result?->cr ?? 0);
    }

    public function getUnclassifiedCashVouchers(int $year): Collection
    {
        return $this->getUnclassifiedCashVouchersForRange(
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->endOfDay()
        );
    }

    /**
     * Bản tổng quát của getUnclassifiedCashVouchers() — nhận trực tiếp khoảng ngày.
     */
    public function getUnclassifiedCashVouchersForRange(Carbon $from, Carbon $to): Collection
    {
        return DB::table('cash_vouchers as cv')
            ->leftJoin('funds as f', 'f.id', '=', 'cv.fund_id')
            ->whereNull('cv.cash_flow_code')
            ->where('cv.status', 'confirmed')
            ->whereBetween('cv.voucher_date', [$from->toDateString(), $to->toDateString()])
            ->select(
                'cv.id', 'cv.code', 'cv.type', 'cv.voucher_date',
                'cv.amount', 'cv.description', 'cv.counterparty', 'cv.business_type',
                'f.name as fund_name'
            )
            ->orderBy('cv.voucher_date')
            ->get();
    }

    public function validateReconciliation(int $year, float $reportedClosing): array
    {
        return $this->validateReconciliationForRange(Carbon::create($year, 12, 31)->endOfDay(), $reportedClosing);
    }

    /**
     * Bản tổng quát của validateReconciliation() — nhận trực tiếp mốc ngày cuối kỳ.
     */
    public function validateReconciliationForRange(Carbon $through, float $reportedClosing): array
    {
        $actualClosing = $this->getEndingCashBalanceAsOf($through);
        $diff          = $reportedClosing - $actualClosing;
        return [
            'reported_closing' => round($reportedClosing),
            'actual_closing'   => round($actualClosing),
            'difference'       => round($diff),
            'ok'               => abs($diff) < 1, // tolerance 1 đồng
        ];
    }

    public function getLineDetail(string $code, int $year): Collection
    {
        return $this->getLineDetailForRange(
            $code,
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->endOfDay()
        );
    }

    /**
     * Bản tổng quát của getLineDetail() — nhận trực tiếp khoảng ngày.
     */
    public function getLineDetailForRange(string $code, Carbon $from, Carbon $to): Collection
    {
        $cashAccounts = $this->resolveCashAccounts();
        if (empty($cashAccounts)) {
            return collect();
        }

        $movements    = $this->getCashMovements($cashAccounts, $from, $to);
        $jeIds        = $movements->pluck('je_id')->unique();
        $counterpartsByJe = $this->getCounterparts($jeIds, $cashAccounts);

        $matchedJeIds = [];
        foreach ($movements as $line) {
            $amount    = (float) $line->debit - (float) $line->credit;
            $direction = $amount >= 0 ? 'in' : 'out';
            $counterparts = $counterpartsByJe[$line->je_id] ?? collect();
            if ($this->classify($direction, $counterparts) === $code) {
                $matchedJeIds[] = $line->je_id;
            }
        }
        $matchedJeIds = array_unique($matchedJeIds);

        if (empty($matchedJeIds)) {
            return collect();
        }

        return DB::table('journal_entries as je')
            ->whereIn('je.id', $matchedJeIds)
            ->select('je.id', 'je.code', 'je.entry_date', 'je.description', 'je.reference_type', 'je.reference_id')
            ->orderBy('je.entry_date')
            ->get();
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function computeAllLines(Carbon $from, Carbon $to): array
    {
        $amounts = array_fill_keys(array_keys(self::LINES), 0.0);
        $cashAccounts = $this->resolveCashAccounts();
        if (empty($cashAccounts)) {
            return $amounts;
        }

        $movements = $this->getCashMovements($cashAccounts, $from, $to);
        $counterpartsByJe = $this->getCounterparts($movements->pluck('je_id')->unique(), $cashAccounts);

        foreach ($movements as $line) {
            $amount    = (float) $line->debit - (float) $line->credit;
            $direction = $amount >= 0 ? 'in' : 'out';
            $counterparts = $counterpartsByJe[$line->je_id] ?? collect();
            $code = $this->classify($direction, $counterparts);
            if (isset($amounts[$code])) {
                $amounts[$code] += $amount;
            }
        }
        return $amounts;
    }

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

    private function getCashMovements(array $cashAccounts, Carbon $from, Carbon $to): Collection
    {
        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('jel.account_code', $cashAccounts)
            ->select('je.id as je_id', 'jel.account_code', 'jel.debit', 'jel.credit')
            ->get();
    }

    private function getCounterparts(Collection $jeIds, array $cashAccounts): Collection
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

    private function classify(string $direction, Collection $counterparts): string
    {
        $map = self::MAP[$direction] ?? [];
        $dominant = $counterparts->sortByDesc(fn ($l) => (float) $l->dr + (float) $l->cr)->first();
        if (!$dominant) {
            return $direction === 'in' ? '06' : '07';
        }
        $code = (string) $dominant->account_code;
        for ($len = min(strlen($code), 5); $len >= 1; $len--) {
            $prefix = substr($code, 0, $len);
            if (isset($map[$prefix])) {
                return $map[$prefix];
            }
        }
        return $direction === 'in' ? '06' : '07';
    }
}
