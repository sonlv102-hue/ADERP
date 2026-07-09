<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\RevenueReportExport;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RevenueReportController extends Controller
{
    public function index(Request $request): Response
    {
        $params = $this->resolveParams($request);
        $data = $this->getReportData($params);

        return Inertia::render('Reports/Revenue/Index', [
            'invoices'       => $data['invoices'],
            'summary'        => $data['summary'],
            'gl_reconcile'   => $data['gl_reconcile'],
            'filters'        => $params,
            'company'        => Setting::getGroup('company'),
            'availableYears' => $this->availableYears(),
            'periodLabel'    => $data['periodLabel'],
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $params = $this->resolveParams($request);
        $data = $this->getReportData($params);
        $company = Setting::getGroup('company');

        return Excel::download(
            new RevenueReportExport(
                $data['invoices'],
                $data['summary'],
                $data['gl_reconcile'],
                $data['periodLabel'],
                $company
            ),
            'bao-cao-doanh-thu-' . $this->fileSlug($params) . '.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        $params = $this->resolveParams($request);
        $data = $this->getReportData($params);
        $company = Setting::getGroup('company');

        return Pdf::loadView('pdf.revenue', [
            'invoices'     => $data['invoices'],
            'summary'      => $data['summary'],
            'gl_reconcile' => $data['gl_reconcile'],
            'periodLabel'  => $data['periodLabel'],
            'company'      => $company,
            'exportDate'   => Carbon::now()->format('d/m/Y H:i'),
        ])
        ->setPaper('a4', 'portrait')
        ->stream('bao-cao-doanh-thu-' . $this->fileSlug($params) . '.pdf');
    }

    private function resolveParams(Request $request): array
    {
        $periodType = $request->input('period_type', 'month');
        $year       = (int) $request->input('year', now()->year);
        $month      = (int) $request->input('month', now()->month);
        $quarter    = (int) $request->input('quarter', (int) ceil(now()->month / 3));

        return [
            'period_type' => $periodType,
            'year'        => $year,
            'month'       => $month,
            'quarter'     => $quarter,
        ];
    }

    private function getReportData(array $params): array
    {
        $year  = $params['year'];
        $month = $params['month'];
        $q     = $params['quarter'];

        if ($params['period_type'] === 'quarter') {
            $startMonth = ($q - 1) * 3 + 1;
            $from = Carbon::create($year, $startMonth, 1)->startOfMonth();
            $to   = Carbon::create($year, $startMonth + 2, 1)->endOfMonth();
            $roman = ['I', 'II', 'III', 'IV'][$q - 1] ?? (string)$q;
            $periodLabel = "Quý {$roman}/{$year}";
        } else {
            $from = Carbon::create($year, $month, 1)->startOfMonth();
            $to   = $from->copy()->endOfMonth();
            $periodLabel = "Tháng " . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "/{$year}";
        }

        // Lấy hóa đơn
        $invoices = DB::table('invoices')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select([
                'invoices.id',
                'invoices.code',
                'invoices.issue_date',
                'invoices.subtotal',
                'invoices.tax_amount',
                'invoices.total',
                'invoices.status',
                'customers.name as customer_name',
            ])
            ->whereBetween('invoices.issue_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('invoices.status', ['sent', 'paid', 'overdue'])
            ->orderBy('invoices.issue_date')
            ->orderBy('invoices.code')
            ->get()
            ->map(function ($row) {
                // Cast properties manually because query builder outputs strings for decimals
                $row->subtotal   = (float)$row->subtotal;
                $row->tax_amount = (float)$row->tax_amount;
                $row->total      = (float)$row->total;
                return $row;
            });

        // Summary
        $summary = [
            'total_subtotal' => (float)$invoices->sum('subtotal'),
            'total_tax'      => (float)$invoices->sum('tax_amount'),
            'total_payment'  => (float)$invoices->sum('total'),
            'count_invoices' => $invoices->count(),
        ];

        // Lấy phát sinh Có tài khoản Doanh thu (511)
        $glRevenue = (float) DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_entry_lines.account_code', 'like', '511%')
            ->selectRaw("SUM(credit - debit) as net_amount")
            ->value('net_amount') ?? 0;

        // Lấy phát sinh Có tài khoản Thuế GTGT đầu ra (3331)
        $glVat = (float) DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_entry_lines.account_code', 'like', '3331%')
            ->selectRaw("SUM(credit - debit) as net_amount")
            ->value('net_amount') ?? 0;

        $hasGlEntries = DB::table('journal_entries')
            ->where('status', 'posted')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->exists();

        $glReconcile = [
            'gl_revenue'     => $glRevenue,
            'revenue_diff'   => $summary['total_subtotal'] - $glRevenue,
            'gl_vat'         => $glVat,
            'vat_diff'       => $summary['total_tax'] - $glVat,
            'has_gl_entries' => $hasGlEntries,
        ];

        return [
            'invoices'     => $invoices,
            'summary'      => $summary,
            'gl_reconcile' => $glReconcile,
            'periodLabel'  => $periodLabel,
        ];
    }

    private function fileSlug(array $params): string
    {
        if ($params['period_type'] === 'quarter') {
            return sprintf('quy-%d-%s', $params['quarter'], $params['year']);
        }
        return sprintf('thang-%02d-%s', $params['month'], $params['year']);
    }

    private function availableYears(): array
    {
        $current = now()->year;
        return range($current - 3, $current + 1);
    }
}
