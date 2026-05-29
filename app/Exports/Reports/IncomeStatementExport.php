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

class IncomeStatementExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Kết quả HĐKD'; }

    public function collection(): Collection
    {
        $year = (int) ($this->filters['year'] ?? now()->year);
        $from = $this->filters['date_from'] ?? "{$year}-01-01";
        $to   = $this->filters['date_to']   ?? "{$year}-12-31";

        $bal = $this->periodBalances($from, $to);
        $b   = fn(string $code) => $bal[$code] ?? 0.0;

        $revenue         = $b('5111') + $b('5113') + $b('512') + $b('515');
        $salesReturn     = $b('521');
        $netRevenue      = $revenue - $salesReturn;
        $cogs            = $b('632');
        $grossProfit     = $netRevenue - $cogs;
        $financialIncome = $b('515');
        $financialExpense= $b('635');
        $sellingExpense  = $b('641');
        $adminExpense    = $b('642');
        $otherIncome     = $b('711');
        $otherExpense    = $b('811');
        $netOpProfit     = $grossProfit + $financialIncome - $financialExpense - $sellingExpense - $adminExpense;
        $ebt             = $netOpProfit + $otherIncome - $otherExpense;
        $cit             = $b('8211');
        $netProfit       = $ebt - $cit;

        return collect([
            (object)['label' => "KẾT QUẢ HOẠT ĐỘNG KINH DOANH từ {$from} đến {$to}", 'amount' => null],
            (object)['label' => '', 'amount' => null],
            (object)['label' => 'Doanh thu bán hàng và CCDV',                 'amount' => $revenue],
            (object)['label' => '  Các khoản giảm trừ doanh thu (TK 521)',    'amount' => -$salesReturn],
            (object)['label' => 'Doanh thu thuần',                             'amount' => $netRevenue],
            (object)['label' => 'Giá vốn hàng bán (TK 632)',                  'amount' => -$cogs],
            (object)['label' => 'Lợi nhuận gộp',                              'amount' => $grossProfit],
            (object)['label' => 'Doanh thu hoạt động tài chính (TK 515)',     'amount' => $financialIncome],
            (object)['label' => 'Chi phí tài chính (TK 635)',                  'amount' => -$financialExpense],
            (object)['label' => 'Chi phí bán hàng (TK 641)',                   'amount' => -$sellingExpense],
            (object)['label' => 'Chi phí QLDN (TK 642)',                       'amount' => -$adminExpense],
            (object)['label' => 'Lợi nhuận thuần từ HĐKD',                    'amount' => $netOpProfit],
            (object)['label' => 'Thu nhập khác (TK 711)',                      'amount' => $otherIncome],
            (object)['label' => 'Chi phí khác (TK 811)',                       'amount' => -$otherExpense],
            (object)['label' => 'Lợi nhuận trước thuế',                       'amount' => $ebt],
            (object)['label' => 'Thuế TNDN (TK 8211)',                         'amount' => -$cit],
            (object)['label' => 'Lợi nhuận sau thuế',                         'amount' => $netProfit],
        ]);
    }

    private function periodBalances(string $from, string $to): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$from, $to])
            ->select('jel.account_code', 'ac.normal_balance',
                DB::raw('SUM(jel.debit) as dr'),
                DB::raw('SUM(jel.credit) as cr'))
            ->groupBy('jel.account_code', 'ac.normal_balance')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr - (float) $r->cr
                : (float) $r->cr - (float) $r->dr;
        }
        return $result;
    }

    public function headings(): array { return ['Chỉ tiêu', 'Giá trị (VND)']; }

    public function map($row): array
    {
        return [$row->label, $row->amount !== null ? $row->amount : ''];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
