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
        $b   = fn(string $prefix) => $this->sumPrefix($bal, $prefix);

        // BUG FIX: $b('642') đã bao gồm 6421+6422. Chỉ trừ totalMgmtExpense một lần.
        $revenue          = $b('511');
        $salesReturn      = $b('521');
        $netRevenue       = $revenue - $salesReturn;
        $cogs             = $b('632');
        $grossProfit      = $netRevenue - $cogs;
        $financialIncome  = $b('515');
        $financialExpense = $b('635');
        $sellingExpense   = $b('6421');   // hiển thị chi tiết
        $adminOnlyExpense = $b('6422');   // hiển thị chi tiết
        $totalMgmtExpense = $b('642');    // dùng trong công thức (bao gồm 6421+6422)
        $otherIncome      = $b('711');
        $otherExpense     = $b('811');
        $netOpProfit      = $grossProfit + $financialIncome - $financialExpense - $totalMgmtExpense;
        $ebt              = $netOpProfit + $otherIncome - $otherExpense;
        $cit              = $b('821');
        $netProfit        = $ebt - $cit;

        return collect([
            (object)['label' => "BÁO CÁO KẾT QUẢ HOẠT ĐỘNG KINH DOANH (B02-DNN — TT133)", 'amount' => null],
            (object)['label' => "Kỳ báo cáo: {$from} đến {$to}", 'amount' => null],
            (object)['label' => '', 'amount' => null],
            (object)['label' => 'Doanh thu bán hàng và CCDV (TK 511)',       'amount' => $revenue],
            (object)['label' => '  Trong đó: TK 5111 — Thương mại',          'amount' => $b('5111')],
            (object)['label' => '  Trong đó: TK 5113 — Dịch vụ/Dự án',      'amount' => $b('5113')],
            (object)['label' => 'Các khoản giảm trừ doanh thu',              'amount' => -$salesReturn],
            (object)['label' => 'Doanh thu thuần (Mã 10)',                    'amount' => $netRevenue],
            (object)['label' => 'Giá vốn hàng bán (TK 632)',                 'amount' => -$cogs],
            (object)['label' => 'Lợi nhuận gộp (Mã 20)',                     'amount' => $grossProfit],
            (object)['label' => 'Doanh thu tài chính (TK 515)',              'amount' => $financialIncome],
            (object)['label' => 'Chi phí tài chính (TK 635)',                'amount' => -$financialExpense],
            (object)['label' => 'Chi phí quản lý kinh doanh (TK 642)',       'amount' => -$totalMgmtExpense],
            (object)['label' => '  Trong đó: Chi phí bán hàng (6421)',       'amount' => -$sellingExpense],
            (object)['label' => '  Trong đó: Chi phí QLDN (6422)',           'amount' => -$adminOnlyExpense],
            (object)['label' => 'Lợi nhuận thuần từ HĐKD (Mã 30)',          'amount' => $netOpProfit],
            (object)['label' => 'Thu nhập khác (TK 711)',                    'amount' => $otherIncome],
            (object)['label' => 'Chi phí khác (TK 811)',                     'amount' => -$otherExpense],
            (object)['label' => 'Lợi nhuận trước thuế (Mã 50)',              'amount' => $ebt],
            (object)['label' => 'Thuế TNDN (TK 821)',                        'amount' => -$cit],
            (object)['label' => 'Lợi nhuận sau thuế (Mã 60)',               'amount' => $netProfit],
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
            // One-sided: expense TK → debit total; revenue TK → credit total
            $result[$r->account_code] = $r->normal_balance === 'debit'
                ? (float) $r->dr
                : (float) $r->cr;
        }
        return $result;
    }

    private function sumPrefix(array $balances, string $prefix): float
    {
        $total = 0.0;
        foreach ($balances as $code => $balance) {
            if (str_starts_with((string) $code, $prefix)) {
                $total += $balance;
            }
        }
        return $total;
    }

    public function headings(): array { return ['Chỉ tiêu', 'Giá trị (VND)']; }

    public function map($row): array
    {
        return [$row->label, $row->amount !== null ? $row->amount : ''];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
