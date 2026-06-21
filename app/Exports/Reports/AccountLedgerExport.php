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

/**
 * S02a/S03a-DNN — Sổ cái / Sổ chi tiết tài khoản.
 * Có cột TK đối ứng (theo yêu cầu TT133).
 */
class AccountLedgerExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        $account = $this->filters['account'] ?? '';
        return $account ? "S02a TK {$account}" : 'Sổ chi tiết tài khoản';
    }

    public function collection(): Collection
    {
        $account  = $this->filters['account'] ?? '131';
        $year     = (int) ($this->filters['year'] ?? now()->year);
        $from     = $this->filters['date_from'] ?? "{$year}-01-01";
        $to       = $this->filters['date_to']   ?? "{$year}-12-31";

        $allAccountsRaw = DB::table('account_codes')
            ->where('is_active', true)
            ->select('code', 'parent_code', 'normal_balance')
            ->get();

        $normalBalance = $allAccountsRaw->firstWhere('code', $account)?->normal_balance ?? 'debit';
        $accountCodes  = $this->resolveDescendants($account, $allAccountsRaw);

        // Số dư đầu kỳ
        $openingData = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jel.account_code', $accountCodes)
            ->where('je.entry_date', '<', $from)
            ->selectRaw('SUM(jel.debit) as dr, SUM(jel.credit) as cr')
            ->first();

        $openingBalance = $normalBalance === 'debit'
            ? (float)($openingData?->dr ?? 0) - (float)($openingData?->cr ?? 0)
            : (float)($openingData?->cr ?? 0) - (float)($openingData?->dr ?? 0);

        // Phát sinh trong kỳ — thêm je.id để tra TK đối ứng
        $lineRows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereIn('jel.account_code', $accountCodes)
            ->whereBetween('je.entry_date', [$from, $to])
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->orderBy('jel.sort_order')
            ->select(
                'je.id as je_id',
                'je.entry_date as date',
                'je.code as ref',
                'je.description as entry_desc',
                'jel.description as line_desc',
                'jel.debit',
                'jel.credit'
            )
            ->get();

        // Tra TK đối ứng: 1 query bổ sung cho tất cả JE IDs
        $counterpartMap = collect();
        if ($lineRows->isNotEmpty()) {
            $jeIds = $lineRows->pluck('je_id')->unique()->values();
            $counterpartMap = DB::table('journal_entry_lines')
                ->whereIn('journal_entry_id', $jeIds)
                ->whereNotIn('account_code', $accountCodes)
                ->select(
                    'journal_entry_id',
                    DB::raw("STRING_AGG(DISTINCT account_code, '/' ORDER BY account_code) as codes")
                )
                ->groupBy('journal_entry_id')
                ->pluck('codes', 'journal_entry_id');
        }

        $result  = [];
        $balance = $openingBalance;

        $result[] = (object) [
            'date'        => '',
            'ref'         => '',
            'description' => 'Số dư đầu kỳ',
            'counterpart' => '',
            'debit'       => 0,
            'credit'      => 0,
            'balance'     => $openingBalance,
        ];

        foreach ($lineRows as $l) {
            $balance += $normalBalance === 'debit'
                ? ((float) $l->debit - (float) $l->credit)
                : ((float) $l->credit - (float) $l->debit);

            $result[] = (object) [
                'date'        => $l->date,
                'ref'         => $l->ref,
                'description' => $l->line_desc ?? $l->entry_desc,
                'counterpart' => $counterpartMap[$l->je_id] ?? '',
                'debit'       => (float) $l->debit,
                'credit'      => (float) $l->credit,
                'balance'     => $balance,
            ];
        }

        $result[] = (object) [
            'date'        => '',
            'ref'         => '',
            'description' => 'Số dư cuối kỳ',
            'counterpart' => '',
            'debit'       => 0,
            'credit'      => 0,
            'balance'     => $balance,
        ];

        return collect($result);
    }

    public function headings(): array
    {
        return ['Ngày', 'Số CT', 'Diễn giải', 'TK đối ứng', 'Nợ (VND)', 'Có (VND)', 'Số dư (VND)'];
    }

    public function map($row): array
    {
        return [
            $row->date,
            $row->ref,
            $row->description,
            $row->counterpart,
            $row->debit  > 0 ? $row->debit  : '',
            $row->credit > 0 ? $row->credit : '',
            $row->balance,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(50);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(18);
        $sheet->getColumnDimension('F')->setWidth(18);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getStyle("E2:G{$highestRow}")->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }

    private function resolveDescendants(string $rootCode, \Illuminate\Support\Collection $allCodes): array
    {
        $result = [$rootCode];
        $queue  = [$rootCode];
        while (!empty($queue)) {
            $parent   = array_shift($queue);
            $children = $allCodes->where('parent_code', $parent)->pluck('code')->all();
            foreach ($children as $child) {
                if (!in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[]  = $child;
                }
            }
        }
        return $result;
    }
}
