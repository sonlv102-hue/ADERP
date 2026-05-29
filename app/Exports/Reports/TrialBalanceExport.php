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

        $accounts = $this->buildAccounts($from, $to);
        return collect($accounts);
    }

    private function buildAccounts(string $from, string $to): array
    {
        $opening = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<', $from)
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $period = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->select('jel.account_code',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'))
            ->groupBy('jel.account_code')
            ->get()->keyBy('account_code');

        $allCodes = $opening->keys()->merge($period->keys())->unique()->sort()->values();

        $accountInfo = DB::table('account_codes')
            ->whereIn('code', $allCodes)
            ->select('code', 'name', 'normal_balance')
            ->get()->keyBy('code');

        $result = [];
        foreach ($allCodes as $code) {
            $acc    = $accountInfo->get($code);
            $openDr = (float) ($opening->get($code)?->total_debit ?? 0);
            $openCr = (float) ($opening->get($code)?->total_credit ?? 0);
            $dr     = (float) ($period->get($code)?->total_debit ?? 0);
            $cr     = (float) ($period->get($code)?->total_credit ?? 0);

            $normalBalance = $acc?->normal_balance ?? 'debit';
            $openingNet    = $openDr - $openCr;

            if ($normalBalance === 'debit') {
                $openingDebit  = max(0.0, $openingNet);
                $openingCredit = max(0.0, -$openingNet);
            } else {
                $openingDebit  = max(0.0, -$openingNet);
                $openingCredit = max(0.0, $openingNet);
            }

            $closingNet = $openingNet + $dr - $cr;
            if ($normalBalance === 'debit') {
                $closingDebit  = max(0.0, $closingNet);
                $closingCredit = max(0.0, -$closingNet);
            } else {
                $closingDebit  = max(0.0, -$closingNet);
                $closingCredit = max(0.0, $closingNet);
            }

            $result[] = (object) [
                'code'           => $code,
                'name'           => $acc?->name ?? '—',
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
