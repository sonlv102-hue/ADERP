<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\IncomeStatementExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncomeStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');

        // Nếu không có filter ngày thì dùng cả năm
        $from = $dateFrom ?: "{$year}-01-01";
        $to   = $dateTo   ?: "{$year}-12-31";

        // Doanh thu (từ hóa đơn bán, không tính draft)
        $revenue = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft'])
            ->whereBetween('issue_date', [$from, $to])
            ->sum('subtotal');

        $vatOut = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft'])
            ->whereBetween('issue_date', [$from, $to])
            ->sum('tax_amount');

        // Giá vốn hàng bán từ đơn hàng
        $cogsOrders = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.order_date', [$from, $to])
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));

        // Chi phí vật tư dự án
        $cogsMaterials = (float) DB::table('project_materials')
            ->join('projects', 'projects.id', '=', 'project_materials.project_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum(DB::raw('project_materials.quantity * project_materials.unit_price'));

        // Chi phí phát sinh dự án
        $cogsExpenses = (float) DB::table('project_expenses')
            ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum('project_expenses.amount');

        $totalCogs    = $cogsOrders + $cogsMaterials + $cogsExpenses;
        $grossProfit  = $revenue - $totalCogs;
        $grossMargin  = $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : null;

        // VAT đầu vào (chi phí)
        $vatIn = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')
            ->whereBetween('invoice_date', [$from, $to])
            ->sum('tax_amount');

        // Chi tiết mua hàng
        $purchaseTotal = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')
            ->whereBetween('invoice_date', [$from, $to])
            ->sum('subtotal');

        // Breakdown theo tháng
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $mFrom = sprintf('%04d-%02d-01', $year, $m);
            $mTo   = date('Y-m-t', strtotime($mFrom));

            $mRevenue = (float) DB::table('invoices')
                ->whereNotIn('status', ['draft'])
                ->whereBetween('issue_date', [$mFrom, $mTo])
                ->sum('subtotal');

            $mCogs = (float) DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->whereBetween('orders.order_date', [$mFrom, $mTo])
                ->whereNotIn('orders.status', ['draft', 'cancelled'])
                ->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));

            $mCogs += (float) DB::table('project_materials')
                ->join('projects', 'projects.id', '=', 'project_materials.project_id')
                ->whereBetween('projects.start_date', [$mFrom, $mTo])
                ->whereNotIn('projects.status', ['cancelled'])
                ->sum(DB::raw('project_materials.quantity * project_materials.unit_price'));

            $mCogs += (float) DB::table('project_expenses')
                ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
                ->whereBetween('projects.start_date', [$mFrom, $mTo])
                ->whereNotIn('projects.status', ['cancelled'])
                ->sum('project_expenses.amount');

            $monthly[] = [
                'month'        => $m,
                'revenue'      => $mRevenue,
                'cogs'         => $mCogs,
                'gross_profit' => $mRevenue - $mCogs,
            ];
        }

        $statement = [
            ['label' => 'Doanh thu bán hàng và CCDV',           'amount' => $revenue,      'bold' => false, 'indent' => 0],
            ['label' => 'Các khoản giảm trừ doanh thu',          'amount' => 0,             'bold' => false, 'indent' => 1],
            ['label' => 'Doanh thu thuần',                        'amount' => $revenue,      'bold' => true,  'indent' => 0],
            ['label' => 'Giá vốn hàng bán',                      'amount' => -$totalCogs,   'bold' => false, 'indent' => 1],
            ['label' => '  - Từ đơn hàng',                       'amount' => -$cogsOrders,  'bold' => false, 'indent' => 2],
            ['label' => '  - Vật tư dự án',                      'amount' => -$cogsMaterials,'bold' => false,'indent' => 2],
            ['label' => '  - Chi phí phát sinh dự án',           'amount' => -$cogsExpenses,'bold' => false, 'indent' => 2],
            ['label' => 'Lợi nhuận gộp',                         'amount' => $grossProfit,  'bold' => true,  'indent' => 0],
            ['label' => 'Doanh thu hoạt động tài chính',          'amount' => 0,             'bold' => false, 'indent' => 0],
            ['label' => 'Chi phí tài chính',                      'amount' => 0,             'bold' => false, 'indent' => 0],
            ['label' => 'Lợi nhuận thuần từ HĐKD',               'amount' => $grossProfit,  'bold' => true,  'indent' => 0],
            ['label' => 'Thu nhập khác',                          'amount' => 0,             'bold' => false, 'indent' => 0],
            ['label' => 'Chi phí khác',                           'amount' => 0,             'bold' => false, 'indent' => 0],
            ['label' => 'Lợi nhuận trước thuế',                  'amount' => $grossProfit,  'bold' => true,  'indent' => 0],
            ['label' => 'Thuế TNDN',                              'amount' => 0,             'bold' => false, 'indent' => 0],
            ['label' => 'Lợi nhuận sau thuế',                    'amount' => $grossProfit,  'bold' => true,  'indent' => 0],
        ];

        $summary = [
            'revenue'         => $revenue,
            'vat_out'         => $vatOut,
            'total_cogs'      => $totalCogs,
            'gross_profit'    => $grossProfit,
            'gross_margin'    => $grossMargin,
            'vat_in'          => $vatIn,
            'purchase_total'  => $purchaseTotal,
        ];

        return Inertia::render('Reports/IncomeStatement/Index', [
            'statement'   => $statement,
            'monthly'     => $monthly,
            'summary'     => $summary,
            'filters'     => $request->only(['year', 'date_from', 'date_to']),
            'currentYear' => $year,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new IncomeStatementExport($request->all()),
            'income-statement-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
