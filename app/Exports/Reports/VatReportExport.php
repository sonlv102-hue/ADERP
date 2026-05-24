<?php

namespace App\Exports\Reports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class VatReportExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string
    {
        return 'Báo cáo VAT';
    }

    public function collection()
    {
        $year = (int) ($this->filters['year'] ?? now()->year);

        $vatOut = DB::table('invoices')
            ->selectRaw("EXTRACT(MONTH FROM issue_date)::int as month, SUM(tax_amount) as vat_out, SUM(subtotal) as revenue")
            ->whereRaw("EXTRACT(YEAR FROM issue_date) = ?", [$year])
            ->whereNotIn('status', ['draft'])
            ->groupByRaw("EXTRACT(MONTH FROM issue_date)")
            ->get()->keyBy('month');

        $vatIn = DB::table('purchase_invoices')
            ->selectRaw("EXTRACT(MONTH FROM invoice_date)::int as month, SUM(tax_amount) as vat_in, SUM(subtotal) as purchase")
            ->whereRaw("EXTRACT(YEAR FROM invoice_date) = ?", [$year])
            ->whereNotNull('invoice_date')
            ->where('status', '!=', 'cancelled')
            ->groupByRaw("EXTRACT(MONTH FROM invoice_date)")
            ->get()->keyBy('month');

        $rows = collect();
        for ($m = 1; $m <= 12; $m++) {
            $out  = (float) ($vatOut[$m]->vat_out ?? 0);
            $in   = (float) ($vatIn[$m]->vat_in   ?? 0);
            $rows->push((object)[
                'month'    => "Tháng {$m}/{$year}",
                'revenue'  => (float) ($vatOut[$m]->revenue  ?? 0),
                'purchase' => (float) ($vatIn[$m]->purchase  ?? 0),
                'vat_out'  => $out,
                'vat_in'   => $in,
                'payable'  => $out - $in,
            ]);
        }
        return $rows;
    }

    public function headings(): array
    {
        return ['Tháng', 'Doanh thu (chưa VAT)', 'Mua vào (chưa VAT)', 'VAT đầu ra', 'VAT đầu vào', 'VAT phải nộp'];
    }

    public function map($row): array
    {
        return [$row->month, $row->revenue, $row->purchase, $row->vat_out, $row->vat_in, $row->payable];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
