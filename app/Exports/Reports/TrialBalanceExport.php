<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TrialBalanceExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Cân đối phát sinh'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";
        $mode = in_array($this->filters['mode'] ?? '', ['raw', 'adjusted']) ? $this->filters['mode'] : 'adjusted';

        $accounts = $this->buildAccounts($from, $to);

        if ($mode === 'adjusted') {
            $accounts = array_values(array_filter($accounts, function ($row) {
                return $row->is_detail || $row->closing_debit > 0 || $row->closing_credit > 0;
            }));
        }

        return collect($accounts);
    }

    private function buildAccounts(string $from, string $to): array
    {
        // Cùng logic với TrialBalanceController: tách số dư đầu kỳ khỏi phát sinh kỳ
        $opening = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where(function ($q) use ($from) {
                $q->where(function ($q2) use ($from) {
                    $q2->where('je.entry_date', '<', $from)
                       ->where('je.exclude_from_period_movement', false);
                })->orWhere('je.exclude_from_period_movement', true);
            })
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $period = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->where('je.exclude_from_period_movement', false)
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $allCodes = $opening->keys()->merge($period->keys())->unique()->sort()->values();

        $accountInfo = DB::table('account_codes')
            ->whereIn('code', $allCodes)
            ->select('code', 'name', 'normal_balance', 'is_detail')
            ->get()->keyBy('code');

        $result = [];
        foreach ($allCodes as $code) {
            $acc    = $accountInfo->get($code);
            $openDr = (float) ($opening->get($code)?->total_debit ?? 0);
            $openCr = (float) ($opening->get($code)?->total_credit ?? 0);
            $dr     = (float) ($period->get($code)?->total_debit ?? 0);
            $cr     = (float) ($period->get($code)?->total_credit ?? 0);

            $openingNet    = $openDr - $openCr;
            $openingDebit  = max(0.0, $openingNet);
            $openingCredit = max(0.0, -$openingNet);

            $closingNet    = $openingNet + $dr - $cr;
            $closingDebit  = max(0.0, $closingNet);
            $closingCredit = max(0.0, -$closingNet);

            $result[] = (object) [
                'code'           => $code,
                'name'           => $acc?->name ?? '—',
                'is_detail'      => (bool) ($acc?->is_detail ?? true),
                'opening_debit'  => $openingDebit,
                'opening_credit' => $openingCredit,
                'dr'             => $dr,
                'cr'             => $cr,
                'closing_debit'  => $closingDebit,
                'closing_credit' => $closingCredit,
            ];
        }

        return $result;
    }

    public function headings(): array
    {
        return ['TK', 'Tên tài khoản', 'Dư đầu kỳ Nợ', 'Dư đầu kỳ Có', 'PS Nợ', 'PS Có', 'Dư cuối kỳ Nợ', 'Dư cuối kỳ Có'];
    }

    public function map($row): array
    {
        return [
            $row->code,
            $row->name,
            $row->opening_debit  > 0 ? $row->opening_debit  : '',
            $row->opening_credit > 0 ? $row->opening_credit : '',
            $row->dr  > 0 ? $row->dr  : '',
            $row->cr  > 0 ? $row->cr  : '',
            $row->closing_debit  > 0 ? $row->closing_debit  : '',
            $row->closing_credit > 0 ? $row->closing_credit : '',
        ];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
