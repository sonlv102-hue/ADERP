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

class BalanceSheetExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Cân đối kế toán'; }

    public function collection(): Collection
    {
        $asOf = $this->filters['as_of'] ?? now()->toDateString();
        $bal  = $this->accountBalancesAsOf($asOf);
        $b    = fn(string $code) => $this->sumPrefix($bal, $code);

        $cashOnHand  = $b('111');
        $bankBalance = $b('112');
        $ar          = $b('131');
        $prepaidST   = $b('142');
        $inventory   = $b('156') + $b('155') + $b('152') + $b('153');
        $faGross     = $b('211') + $b('213');
        $faAccDep    = $b('214');
        $faNet       = max(0.0, $faGross - $faAccDep);
        $prepaidLT   = $b('242');

        $cash                  = $cashOnHand + $bankBalance;
        $totalCurrentAssets    = $cash + $ar + $prepaidST + $inventory;
        $totalNonCurrentAssets = $faNet + $prepaidLT;
        $totalAssets           = $totalCurrentAssets + $totalNonCurrentAssets;

        $ap            = $b('331');
        $vatPayable    = $b('3331') + $b('3332') + $b('3333');
        $citPayable    = $b('3334');
        $pitPayable    = $b('3335');
        $bhxhPayable   = $b('3383') + $b('3384') + $b('3389');
        $salaryPayable = $b('334');
        $totalLiabilities = $ap + $vatPayable + $citPayable + $pitPayable + $bhxhPayable + $salaryPayable;

        $charterCapital = $b('411');
        $revenue  = $this->sumPrefix($bal, '5');
        $expenses = $this->sumPrefix($bal, '6') + $this->sumPrefix($bal, '8');
        $retainedEarnings = $revenue - $expenses;
        $totalEquity     = $charterCapital + $retainedEarnings;
        $totalLiabEquity = $totalLiabilities + $totalEquity;

        return collect([
            (object)['label' => 'BẢNG CÂN ĐỐI KẾ TOÁN tại ' . $asOf, 'amount' => null],
            (object)['label' => '', 'amount' => null],
            (object)['label' => 'A. TÀI SẢN NGẮN HẠN',                       'amount' => $totalCurrentAssets],
            (object)['label' => '  I. Tiền và tương đương tiền',               'amount' => $cash],
            (object)['label' => '     - Tiền mặt (TK 111)',                    'amount' => $cashOnHand],
            (object)['label' => '     - Tiền gửi ngân hàng (TK 112)',          'amount' => $bankBalance],
            (object)['label' => '  II. Phải thu ngắn hạn – KH (TK 131)',      'amount' => $ar],
            (object)['label' => '  III. Hàng tồn kho (TK 152/153/155/156)',   'amount' => $inventory],
            (object)['label' => '  IV. Chi phí trả trước ngắn hạn (TK 142)', 'amount' => $prepaidST],
            (object)['label' => 'B. TÀI SẢN DÀI HẠN',                        'amount' => $totalNonCurrentAssets],
            (object)['label' => '  I. TSCĐ – Nguyên giá (TK 211)',            'amount' => $faGross],
            (object)['label' => '     Hao mòn lũy kế (TK 214)',               'amount' => -$faAccDep],
            (object)['label' => '     Giá trị còn lại',                       'amount' => $faNet],
            (object)['label' => '  II. Chi phí trả trước dài hạn (TK 242)',   'amount' => $prepaidLT],
            (object)['label' => 'TỔNG CỘNG TÀI SẢN (A+B)',                    'amount' => $totalAssets],
            (object)['label' => '', 'amount' => null],
            (object)['label' => 'A. NỢ PHẢI TRẢ',                             'amount' => $totalLiabilities],
            (object)['label' => '  I. Phải trả người bán (TK 331)',            'amount' => $ap],
            (object)['label' => '  II. Thuế GTGT phải nộp (TK 3331)',         'amount' => $vatPayable],
            (object)['label' => '  III. Thuế TNDN (TK 3334)',                  'amount' => $citPayable],
            (object)['label' => '  IV. Thuế TNCN (TK 3335)',                   'amount' => $pitPayable],
            (object)['label' => '  V. Phải trả NLĐ (TK 334)',                  'amount' => $salaryPayable],
            (object)['label' => '  VI. BHXH/BHYT/BHTN (TK 338)',               'amount' => $bhxhPayable],
            (object)['label' => 'B. VỐN CHỦ SỞ HỮU',                          'amount' => $totalEquity],
            (object)['label' => '  Vốn đầu tư của CSH (TK 411)',               'amount' => $charterCapital],
            (object)['label' => '  Lợi nhuận chưa phân phối',                  'amount' => $retainedEarnings],
            (object)['label' => 'TỔNG CỘNG NGUỒN VỐN (A+B)',                   'amount' => $totalLiabEquity],
        ]);
    }

    private function accountBalancesAsOf(string $asOf): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $asOf)
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

    public function headings(): array { return ['Chỉ tiêu', 'Số tiền (VND)']; }

    public function map($row): array
    {
        return [$row->label, $row->amount !== null ? $row->amount : ''];
    }

    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
