<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\BalanceSheetExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BalanceSheetController extends Controller
{
    public function index(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());

        // ── ASSETS ──────────────────────────────────────────────────────────

        // TK 112 – Tiền: thu từ KH − trả NCC
        $cashIn  = (float) DB::table('payments')->where('payment_date', '<=', $asOf)->sum('amount');
        $cashOut = (float) DB::table('purchase_invoice_payments')->where('payment_date', '<=', $asOf)->sum('amount');
        $cash    = $cashIn - $cashOut;

        // TK 131 – Phải thu KH
        $totalInvoiced  = (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled'])
            ->where('issue_date', '<=', $asOf)
            ->sum('total');
        $ar = max(0, $totalInvoiced - $cashIn);

        // TK 156 – Hàng tồn kho
        $stockIn  = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', 'in')
            ->where('stock_movements.created_at', '<=', $asOf . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stockOut = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', 'out')
            ->where('stock_movements.created_at', '<=', $asOf . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $inventory = max(0, $stockIn - $stockOut);

        // TK 211/214 – TSCĐ
        $faGross = (float) DB::table('fixed_assets')
            ->whereNull('deleted_at')
            ->where('acquisition_date', '<=', $asOf)
            ->sum('acquisition_cost');
        $faAccDep = (float) DB::table('fixed_assets')
            ->whereNull('deleted_at')
            ->where('acquisition_date', '<=', $asOf)
            ->sum('accumulated_depreciation');
        $faNet = max(0, $faGross - $faAccDep);

        $totalCurrentAssets    = $cash + $ar + $inventory;
        $totalNonCurrentAssets = $faNet;
        $totalAssets           = $totalCurrentAssets + $totalNonCurrentAssets;

        // ── LIABILITIES ──────────────────────────────────────────────────────

        // TK 331 – Phải trả NCC
        $ap = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled'])
            ->where('invoice_date', '<=', $asOf)
            ->sum(DB::raw('GREATEST(0, total - paid_amount)'));

        // TK 3331 – Thuế GTGT phải nộp
        $vatOut     = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('issue_date', '<=', $asOf)
            ->sum('tax_amount');
        $vatIn      = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')
            ->where('invoice_date', '<=', $asOf)
            ->sum('tax_amount');
        $vatPayable = max(0, $vatOut - $vatIn);

        $totalLiabilities = $ap + $vatPayable;

        // ── EQUITY ───────────────────────────────────────────────────────────

        $revenue = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('issue_date', '<=', $asOf)
            ->sum('subtotal');

        $cogsOrders = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.order_date', '<=', $asOf)
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));

        $cogsMaterials = (float) DB::table('project_materials')
            ->join('projects', 'projects.id', '=', 'project_materials.project_id')
            ->where('projects.start_date', '<=', $asOf)
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum(DB::raw('project_materials.quantity * project_materials.unit_price'));

        $cogsExpenses = (float) DB::table('project_expenses')
            ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
            ->where('projects.start_date', '<=', $asOf)
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum('project_expenses.amount');

        $retainedEarnings  = $revenue - $cogsOrders - $cogsMaterials - $cogsExpenses;
        $totalEquity       = $retainedEarnings;
        $totalLiabEquity   = $totalLiabilities + $totalEquity;

        $balanceSheet = [
            ['label' => 'A. TÀI SẢN NGẮN HẠN',                                           'amount' => $totalCurrentAssets,    'bold' => true,  'indent' => 0, 'side' => 'asset'],
            ['label' => 'I. Tiền và tương đương tiền (TK 112)',                            'amount' => $cash,                  'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'II. Phải thu ngắn hạn – Phải thu của KH (TK 131)',               'amount' => $ar,                    'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'III. Hàng tồn kho (TK 156)',                                      'amount' => $inventory,             'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => 'B. TÀI SẢN DÀI HẠN',                                            'amount' => $totalNonCurrentAssets, 'bold' => true,  'indent' => 0, 'side' => 'asset'],
            ['label' => 'I. TSCĐ hữu hình – Nguyên giá (TK 211)',                         'amount' => $faGross,               'bold' => false, 'indent' => 1, 'side' => 'asset'],
            ['label' => '   Hao mòn lũy kế (TK 214)',                                     'amount' => -$faAccDep,             'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => '   Giá trị còn lại',                                             'amount' => $faNet,                 'bold' => false, 'indent' => 2, 'side' => 'asset'],
            ['label' => 'TỔNG CỘNG TÀI SẢN (A+B)',                                        'amount' => $totalAssets,           'bold' => true,  'indent' => 0, 'side' => 'total_asset'],

            ['label' => 'A. NỢ PHẢI TRẢ',                                                  'amount' => $totalLiabilities,      'bold' => true,  'indent' => 0, 'side' => 'liability'],
            ['label' => 'I. Phải trả người bán ngắn hạn (TK 331)',                         'amount' => $ap,                    'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => 'II. Thuế GTGT phải nộp (TK 3331)',                               'amount' => $vatPayable,            'bold' => false, 'indent' => 1, 'side' => 'liability'],
            ['label' => 'B. VỐN CHỦ SỞ HỮU',                                              'amount' => $totalEquity,           'bold' => true,  'indent' => 0, 'side' => 'equity'],
            ['label' => 'Lợi nhuận chưa phân phối',                                        'amount' => $retainedEarnings,      'bold' => false, 'indent' => 1, 'side' => 'equity'],
            ['label' => 'TỔNG CỘNG NGUỒN VỐN (A+B)',                                      'amount' => $totalLiabEquity,       'bold' => true,  'indent' => 0, 'side' => 'total_equity'],
        ];

        $summary = [
            'total_assets'           => $totalAssets,
            'total_liabilities'      => $totalLiabilities,
            'total_equity'           => $totalEquity,
            'total_liabilities_equity' => $totalLiabEquity,
            'balanced'               => abs($totalAssets - $totalLiabEquity) < 1,
        ];

        return Inertia::render('Reports/BalanceSheet/Index', [
            'balanceSheet' => $balanceSheet,
            'summary'      => $summary,
            'filters'      => ['as_of' => $asOf],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new BalanceSheetExport($request->all()),
            'balance-sheet-' . $request->input('as_of', now()->toDateString()) . '.xlsx'
        );
    }
}
