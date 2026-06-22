<?php

namespace App\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncomeStatementService
{
    // [label, isSummary, formula]
    public const LINES = [
        '01' => ['Doanh thu bán hàng và cung cấp dịch vụ',            false, null],
        '02' => ['Các khoản giảm trừ doanh thu',                       false, null],
        '10' => ['Doanh thu thuần về bán hàng và cung cấp dịch vụ',   true,  '10 = 01 - 02'],
        '11' => ['Giá vốn hàng bán',                                   false, null],
        '20' => ['Lợi nhuận gộp về bán hàng và cung cấp dịch vụ',     true,  '20 = 10 - 11'],
        '21' => ['Doanh thu hoạt động tài chính',                      false, null],
        '22' => ['Chi phí tài chính',                                  false, null],
        '23' => ['- Trong đó: Chi phí lãi vay',                        false, null],
        '24' => ['Chi phí quản lý kinh doanh',                         false, null],
        '30' => ['Lợi nhuận thuần từ hoạt động kinh doanh',            true,  '30 = 20 + 21 - 22 - 24'],
        '31' => ['Thu nhập khác',                                       false, null],
        '32' => ['Chi phí khác',                                        false, null],
        '40' => ['Lợi nhuận khác',                                      true,  '40 = 31 - 32'],
        '50' => ['Tổng lợi nhuận kế toán trước thuế',                  true,  '50 = 30 + 40'],
        '51' => ['Chi phí thuế thu nhập doanh nghiệp',                 false, null],
        '60' => ['Lợi nhuận sau thuế thu nhập doanh nghiệp',           true,  '60 = 50 - 51'],
    ];

    // [direction, prefixes[]]  — direction: 'credit' for revenue TK, 'debit' for expense TK
    private const ACCOUNT_MAP = [
        '01' => ['credit', ['511']],
        '02' => ['debit',  ['521']],
        '11' => ['debit',  ['632']],
        '21' => ['credit', ['515']],
        '22' => ['debit',  ['635']],
        '24' => ['debit',  ['642']],
        '31' => ['credit', ['711']],
        '32' => ['debit',  ['811']],
        '51' => ['debit',  ['821']],
    ];

    public function getReport(int $year, string $unit = 'dong'): array
    {
        $currValues = $this->buildRows($year);
        $prevValues = $this->buildRows($year - 1);
        $divisor    = match ($unit) {
            'nghin_dong'  => 1_000,
            'trieu_dong'  => 1_000_000,
            default       => 1,
        };

        $rows = [];
        foreach (array_keys(self::LINES) as $code) {
            [$label, $isSummary, $formula] = self::LINES[$code];
            $rows[] = [
                'code'      => $code,
                'label'     => $label,
                'isSummary' => $isSummary,
                'formula'   => $formula,
                'note'      => null,
                'curr'      => round(($currValues[$code] ?? 0) / $divisor),
                'prev'      => round(($prevValues[$code] ?? 0) / $divisor),
            ];
        }

        return [
            'year'     => $year,
            'unit'     => $unit,
            'rows'     => $rows,
            'warnings' => $this->validateDataQuality($year),
        ];
    }

    public function buildRows(int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to   = Carbon::create($year, 12, 31)->endOfDay();

        $bal = $this->periodBalances($from, $to);
        $b   = fn(string $prefix) => $this->sumPrefix($bal, $prefix);

        $v = [
            '01' => $b('511'),
            '02' => $b('521'),
            '11' => $b('632'),
            '21' => $b('515'),
            '22' => $b('635'),
            '23' => $this->getInterestExpense($from, $to),
            '24' => $b('642'),
            '31' => $b('711'),
            '32' => $b('811'),
            '51' => $b('821'),
        ];

        $v['10'] = $v['01'] - $v['02'];
        $v['20'] = $v['10'] - $v['11'];
        $v['30'] = $v['20'] + $v['21'] - $v['22'] - $v['24'];
        $v['40'] = $v['31'] - $v['32'];
        $v['50'] = $v['30'] + $v['40'];
        $v['60'] = $v['50'] - $v['51'];

        return $v;
    }

    public function getLineAmount(string $code, Carbon $from, Carbon $to): float
    {
        if ($code === '23') {
            return $this->getInterestExpense($from, $to);
        }

        if (isset(self::ACCOUNT_MAP[$code])) {
            [$direction, $prefixes] = self::ACCOUNT_MAP[$code];
            $bal   = $this->periodBalances($from, $to);
            $total = 0.0;
            foreach ($prefixes as $prefix) {
                $total += $this->sumPrefix($bal, $prefix);
            }
            return $total;
        }

        $year = (int) $from->format('Y');
        return $this->buildRows($year)[$code] ?? 0.0;
    }

    public function getDetailEntries(string $code, int $year): Collection
    {
        $map = self::ACCOUNT_MAP[$code] ?? null;
        if ($map === null) {
            return collect();
        }
        [$direction, $prefixes] = $map;
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to   = Carbon::create($year, 12, 31)->endOfDay();

        $like = collect($prefixes)
            ->map(fn($p) => "jel.account_code LIKE '{$p}%'")
            ->implode(' OR ');

        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereRaw("({$like})")
            ->where($direction === 'credit' ? 'jel.credit' : 'jel.debit', '>', 0)
            ->select(
                'je.entry_date as date',
                'je.code as je_code',
                'je.id as je_id',
                'je.description',
                'je.reference_type',
                'je.reference_id',
                'jel.account_code',
                'jel.debit',
                'jel.credit',
            )
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->get()
            ->map(fn($r) => [
                'date'           => $r->date,
                'je_code'        => $r->je_code,
                'je_id'          => $r->je_id,
                'description'    => $r->description,
                'reference_type' => $r->reference_type,
                'reference_id'   => $r->reference_id,
                'account_code'   => $r->account_code,
                'debit'          => (float) $r->debit,
                'credit'         => (float) $r->credit,
                'amount'         => $direction === 'credit' ? (float) $r->credit : (float) $r->debit,
            ]);
    }

    public function validateFormulas(array $rows): array
    {
        $v    = collect($rows)->keyBy('code')->map(fn($r) => $r['curr'])->toArray();
        $get  = fn($code) => $v[$code] ?? 0.0;
        $errs = [];

        $checks = [
            '10' => fn() => $get('01') - $get('02'),
            '20' => fn() => $get('10') - $get('11'),
            '30' => fn() => $get('20') + $get('21') - $get('22') - $get('24'),
            '40' => fn() => $get('31') - $get('32'),
            '50' => fn() => $get('30') + $get('40'),
            '60' => fn() => $get('50') - $get('51'),
        ];

        foreach ($checks as $code => $fn) {
            $expected = $fn();
            $actual   = $get($code);
            if (abs($actual - $expected) > 1) {
                $errs[] = "Mã {$code}: báo cáo=" . number_format($actual) . ', tính lại=' . number_format($expected);
            }
        }

        return $errs;
    }

    public function validateDataQuality(int $year): array
    {
        $from     = "{$year}-01-01";
        $to       = "{$year}-12-31";
        $warnings = [];

        $bal     = $this->periodBalances(Carbon::parse($from), Carbon::parse($to));
        $revenue = $this->sumPrefix($bal, '511');
        $cogs    = $this->sumPrefix($bal, '632');

        if ($revenue > 0 && $cogs === 0.0) {
            $warnings[] = ['level' => 'warning', 'message' => 'Có doanh thu (TK 511) nhưng giá vốn (TK 632) = 0. Kiểm tra phiếu xuất kho đã posted chưa.'];
        }

        $draftCount = DB::table('journal_entries')
            ->where('status', 'draft')
            ->whereBetween('entry_date', [$from, $to])
            ->count();
        if ($draftCount > 0) {
            $warnings[] = ['level' => 'warning', 'message' => "Có {$draftCount} bút toán chưa posted trong năm {$year} — số liệu chưa đầy đủ."];
        }

        $has911 = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('jel.account_code', '911')
            ->exists();
        if ($has911) {
            $warnings[] = ['level' => 'info', 'message' => 'Đã có bút toán kết chuyển (TK 911). Báo cáo tính theo phát sinh thuần — không bị ảnh hưởng bởi kết chuyển.'];
        }

        return $warnings;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * Một chiều theo normal_balance: revenue TK → Cr side; expense TK → Dr side.
     * Miễn nhiễm với kết chuyển 911 vì kết chuyển tác động vào chiều ngược lại.
     */
    private function periodBalances(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->select('jel.account_code', 'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr'))
            ->groupBy('jel.account_code', 'ac.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr
                : (float) $r->cr;
        }
        return $result;
    }

    private function sumPrefix(array $balances, string $prefix): float
    {
        $total = 0.0;
        foreach ($balances as $code => $amount) {
            if (str_starts_with((string) $code, $prefix)) {
                $total += $amount;
            }
        }
        return $total;
    }

    /**
     * Chi phí lãi vay (mã 23): tìm JE có Dr TK635 và Cr TK vay (311/341).
     * Nếu không xác định được, trả về 0 để tránh inflate mã 23 > mã 22.
     */
    private function getInterestExpense(Carbon $from, Carbon $to): float
    {
        $jeIds = DB::table('journal_entry_lines as a')
            ->join('journal_entries as je', 'je.id', '=', 'a.journal_entry_id')
            ->join('journal_entry_lines as b', 'b.journal_entry_id', '=', 'a.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('a.account_code', 'LIKE', '635%')
            ->where('a.debit', '>', 0)
            ->where('b.credit', '>', 0)
            ->where(function ($q) {
                $q->where('b.account_code', 'LIKE', '311%')
                  ->orWhere('b.account_code', 'LIKE', '341%');
            })
            ->pluck('a.journal_entry_id')
            ->unique();

        if ($jeIds->isEmpty()) {
            return 0.0;
        }

        return (float) DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $jeIds)
            ->where('account_code', 'LIKE', '635%')
            ->sum('debit');
    }
}
