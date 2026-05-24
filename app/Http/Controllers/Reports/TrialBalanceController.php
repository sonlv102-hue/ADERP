<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TrialBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $prevDate = date('Y-m-d', strtotime($dateFrom . ' -1 day'));

        $accounts = $this->buildAccounts($dateFrom, $dateTo, $prevDate);

        $totals = [
            'opening_debit'  => array_sum(array_column($accounts, 'opening_debit')),
            'opening_credit' => array_sum(array_column($accounts, 'opening_credit')),
            'debit'          => array_sum(array_column($accounts, 'debit')),
            'credit'         => array_sum(array_column($accounts, 'credit')),
            'closing_debit'  => array_sum(array_column($accounts, 'closing_debit')),
            'closing_credit' => array_sum(array_column($accounts, 'closing_credit')),
        ];

        return Inertia::render('Reports/TrialBalance/Index', [
            'accounts' => $accounts,
            'totals'   => $totals,
            'filters'  => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'currentYear' => $year,
        ]);
    }

    private function buildAccounts(string $from, string $to, string $prev): array
    {
        $accounts = [];

        // ── TK 112: Tiền gửi ngân hàng (debit-normal) ───────────────────────
        $cashInBefore  = (float) DB::table('payments')->where('payment_date', '<=', $prev)->sum('amount');
        $cashOutBefore = (float) DB::table('purchase_invoice_payments')->where('payment_date', '<=', $prev)->sum('amount');
        $cashInPeriod  = (float) DB::table('payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $cashOutPeriod = (float) DB::table('purchase_invoice_payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $accounts[] = $this->makeAccount('112', 'Tiền gửi ngân hàng', 'debit',
            $cashInBefore - $cashOutBefore, $cashInPeriod, $cashOutPeriod);

        // ── TK 131: Phải thu của KH (debit-normal) ───────────────────────────
        $arDrBefore = (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled'])->where('issue_date', '<=', $prev)->sum('total');
        $arCrBefore = (float) DB::table('payments')->where('payment_date', '<=', $prev)->sum('amount');
        $arDrPeriod = (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled'])->whereBetween('issue_date', [$from, $to])->sum('total');
        $arCrPeriod = (float) DB::table('payments')->whereBetween('payment_date', [$from, $to])->sum('amount');
        $accounts[] = $this->makeAccount('131', 'Phải thu của khách hàng', 'debit',
            $arDrBefore - $arCrBefore, $arDrPeriod, $arCrPeriod);

        // ── TK 133: Thuế GTGT được KT (debit-normal) ────────────────────────
        $vatInBefore = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')->where('invoice_date', '<=', $prev)->sum('tax_amount');
        $vatInPeriod = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')->whereBetween('invoice_date', [$from, $to])->sum('tax_amount');
        $accounts[] = $this->makeAccount('133', 'Thuế GTGT được khấu trừ', 'debit',
            $vatInBefore, $vatInPeriod, 0);

        // ── TK 156: Hàng hóa (debit-normal) ─────────────────────────────────
        $stInBefore  = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'in')->where('stock_movements.created_at', '<=', $prev . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stOutBefore = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'out')->where('stock_movements.created_at', '<=', $prev . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stInPeriod  = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'in')->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stOutPeriod = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'out')->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $accounts[] = $this->makeAccount('156', 'Hàng hóa', 'debit',
            $stInBefore - $stOutBefore, $stInPeriod, $stOutPeriod);

        // ── TK 211: TSCĐ hữu hình (debit-normal) ────────────────────────────
        $faBefore = (float) DB::table('fixed_assets')
            ->whereNull('deleted_at')->where('acquisition_date', '<=', $prev)->sum('acquisition_cost');
        $faPeriod = (float) DB::table('fixed_assets')
            ->whereNull('deleted_at')->whereBetween('acquisition_date', [$from, $to])->sum('acquisition_cost');
        $accounts[] = $this->makeAccount('211', 'Tài sản cố định hữu hình', 'debit',
            $faBefore, $faPeriod, 0);

        // ── TK 214: Hao mòn TSCĐ (credit-normal) ───────────────────────────
        $depBefore = (float) DB::table('fixed_assets')
            ->whereNull('deleted_at')->where('acquisition_date', '<=', $prev)->sum('accumulated_depreciation');
        $depPeriod = 0; // simplified: accumulated depreciation change not tracked per period
        $accounts[] = $this->makeAccount('214', 'Hao mòn lũy kế TSCĐ', 'credit',
            -$depBefore, 0, $depPeriod ?: $depBefore - $depBefore);

        // ── TK 331: Phải trả người bán (credit-normal) ──────────────────────
        $apCrBefore = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled'])->where('invoice_date', '<=', $prev)->sum('total');
        $apDrBefore = (float) DB::table('purchase_invoice_payments')
            ->where('payment_date', '<=', $prev)->sum('amount');
        $apCrPeriod = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled'])->whereBetween('invoice_date', [$from, $to])->sum('total');
        $apDrPeriod = (float) DB::table('purchase_invoice_payments')
            ->whereBetween('payment_date', [$from, $to])->sum('amount');
        $accounts[] = $this->makeAccount('331', 'Phải trả người bán', 'credit',
            $apCrBefore - $apDrBefore, $apDrPeriod, $apCrPeriod);

        // ── TK 3331: Thuế GTGT phải nộp (credit-normal) ─────────────────────
        $vatOutBefore = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])->where('issue_date', '<=', $prev)->sum('tax_amount');
        $vatInBefore2 = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')->where('invoice_date', '<=', $prev)->sum('tax_amount');
        $vatOutPeriod = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('issue_date', [$from, $to])->sum('tax_amount');
        $vatInPeriod2 = (float) DB::table('purchase_invoices')
            ->whereNotNull('invoice_date')->whereBetween('invoice_date', [$from, $to])->sum('tax_amount');
        $accounts[] = $this->makeAccount('3331', 'Thuế GTGT phải nộp', 'credit',
            $vatOutBefore - $vatInBefore2, $vatInPeriod2, $vatOutPeriod);

        // ── TK 511: Doanh thu bán hàng (credit-normal, P&L reset) ───────────
        $revPeriod = (float) DB::table('invoices')
            ->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('issue_date', [$from, $to])->sum('subtotal');
        $accounts[] = $this->makeAccount('511', 'Doanh thu bán hàng và CCDV', 'credit',
            0, 0, $revPeriod);

        // ── TK 632: Giá vốn hàng bán (debit-normal, P&L reset) ──────────────
        $cogsPeriod = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('orders.order_date', [$from, $to])
            ->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));
        $cogsPeriod += (float) DB::table('project_materials')
            ->join('projects', 'projects.id', '=', 'project_materials.project_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum(DB::raw('project_materials.quantity * project_materials.unit_price'));
        $accounts[] = $this->makeAccount('632', 'Giá vốn hàng bán', 'debit',
            0, $cogsPeriod, 0);

        // ── TK 641: Chi phí bán hàng (hoa hồng) ────────────────────────────
        $commPeriod = (float) DB::table('commissions')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->sum('amount');
        $accounts[] = $this->makeAccount('641', 'Chi phí bán hàng', 'debit', 0, $commPeriod, 0);

        // ── TK 642: Chi phí QLDN (project expenses) ─────────────────────────
        $gadPeriod = (float) DB::table('project_expenses')
            ->join('projects', 'projects.id', '=', 'project_expenses.project_id')
            ->whereBetween('projects.start_date', [$from, $to])
            ->whereNotIn('projects.status', ['cancelled'])
            ->sum('project_expenses.amount');
        $accounts[] = $this->makeAccount('642', 'Chi phí quản lý doanh nghiệp', 'debit', 0, $gadPeriod, 0);

        return $accounts;
    }

    private function makeAccount(string $code, string $name, string $normalBalance, float $openingNet, float $dr, float $cr): array
    {
        if ($normalBalance === 'debit') {
            $openingDebit  = max(0, $openingNet);
            $openingCredit = max(0, -$openingNet);
        } else {
            $openingDebit  = max(0, -$openingNet);
            $openingCredit = max(0, $openingNet);
        }

        $closingNet = ($openingDebit - $openingCredit) + $dr - $cr;
        if ($normalBalance === 'debit') {
            $closingDebit  = max(0, $closingNet);
            $closingCredit = max(0, -$closingNet);
        } else {
            $closingDebit  = max(0, -$closingNet);
            $closingCredit = max(0, $closingNet);
        }

        return compact('code', 'name', 'openingDebit', 'openingCredit', 'dr', 'cr', 'closingDebit', 'closingCredit');
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new TrialBalanceExport($request->all()),
            'trial-balance-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
