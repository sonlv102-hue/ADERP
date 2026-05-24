<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\VatReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VatReportController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->year);

        // VAT đầu ra — từ hóa đơn bán (theo tháng)
        $vatOut = DB::table('invoices')
            ->selectRaw("EXTRACT(MONTH FROM issue_date)::int as month, SUM(tax_amount) as vat_out, SUM(subtotal) as revenue")
            ->whereRaw("EXTRACT(YEAR FROM issue_date) = ?", [$year])
            ->whereNotIn('status', ['draft'])
            ->groupByRaw("EXTRACT(MONTH FROM issue_date)")
            ->get()
            ->keyBy('month');

        // VAT đầu vào — từ hóa đơn mua (theo tháng), loại trừ đã hủy
        $vatIn = DB::table('purchase_invoices')
            ->selectRaw("EXTRACT(MONTH FROM invoice_date)::int as month, SUM(tax_amount) as vat_in, SUM(subtotal) as purchase")
            ->whereRaw("EXTRACT(YEAR FROM invoice_date) = ?", [$year])
            ->whereNotNull('invoice_date')
            ->where('status', '!=', 'cancelled')
            ->groupByRaw("EXTRACT(MONTH FROM invoice_date)")
            ->get()
            ->keyBy('month');

        // Hợp nhất theo 12 tháng
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $out      = (float) ($vatOut[$m]->vat_out  ?? 0);
            $in       = (float) ($vatIn[$m]->vat_in    ?? 0);
            $revenue  = (float) ($vatOut[$m]->revenue  ?? 0);
            $purchase = (float) ($vatIn[$m]->purchase  ?? 0);
            $months[] = [
                'month'    => $m,
                'revenue'  => $revenue,
                'purchase' => $purchase,
                'vat_out'  => $out,
                'vat_in'   => $in,
                'payable'  => $out - $in,
            ];
        }

        // Chi tiết hóa đơn theo tháng được chọn
        $detailMonth = (int) $request->input('detail_month', 0);
        $detailType  = $request->input('detail_type', 'out'); // 'out' | 'in'
        $details     = [];

        if ($detailMonth >= 1 && $detailMonth <= 12) {
            if ($detailType === 'out') {
                $details = DB::table('invoices')
                    ->join('customers', 'customers.id', '=', 'invoices.customer_id')
                    ->selectRaw("invoices.code, customers.name as party, invoices.issue_date as doc_date, invoices.subtotal, invoices.tax_amount")
                    ->whereRaw("EXTRACT(YEAR FROM issue_date) = ?", [$year])
                    ->whereRaw("EXTRACT(MONTH FROM issue_date) = ?", [$detailMonth])
                    ->whereNotIn('invoices.status', ['draft'])
                    ->orderBy('invoices.issue_date')
                    ->get();
            } else {
                $details = DB::table('purchase_invoices')
                    ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
                    ->selectRaw("purchase_invoices.code, suppliers.name as party, purchase_invoices.invoice_date as doc_date, purchase_invoices.subtotal, purchase_invoices.tax_amount")
                    ->whereRaw("EXTRACT(YEAR FROM invoice_date) = ?", [$year])
                    ->whereRaw("EXTRACT(MONTH FROM invoice_date) = ?", [$detailMonth])
                    ->whereNotNull('purchase_invoices.invoice_date')
                    ->where('purchase_invoices.status', '!=', 'cancelled')
                    ->orderBy('purchase_invoices.invoice_date')
                    ->get();
            }
        }

        $summary = [
            'total_vat_out'  => collect($months)->sum('vat_out'),
            'total_vat_in'   => collect($months)->sum('vat_in'),
            'total_payable'  => collect($months)->sum('payable'),
            'total_revenue'  => collect($months)->sum('revenue'),
            'total_purchase' => collect($months)->sum('purchase'),
        ];

        return Inertia::render('Reports/VAT/Index', [
            'months'        => $months,
            'summary'       => $summary,
            'details'       => $details,
            'filters'       => $request->only(['year', 'detail_month', 'detail_type']),
            'currentYear'   => $year,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new VatReportExport($request->all()),
            'vat-report-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
