<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\AccountLedgerExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountLedgerController extends Controller
{
    private const ACCOUNTS = [
        '131' => 'Phải thu của khách hàng',
        '331' => 'Phải trả người bán',
        '156' => 'Hàng hóa',
    ];

    public function index(Request $request): Response
    {
        $account  = $request->input('account', '131');
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $prevDate = date('Y-m-d', strtotime($dateFrom . ' -1 day'));

        if (!array_key_exists($account, self::ACCOUNTS)) {
            $account = '131';
        }

        [$openingBalance, $rows] = match ($account) {
            '131'   => $this->buildTK131($dateFrom, $dateTo, $prevDate),
            '331'   => $this->buildTK331($dateFrom, $dateTo, $prevDate),
            '156'   => $this->buildTK156($dateFrom, $dateTo, $prevDate),
            default => $this->buildTK131($dateFrom, $dateTo, $prevDate),
        };

        // Compute running balance
        $balance = $openingBalance;
        foreach ($rows as &$row) {
            if ($account === '331') {
                $balance = $balance + $row['credit'] - $row['debit'];
            } else {
                $balance = $balance + $row['debit'] - $row['credit'];
            }
            $row['balance'] = $balance;
        }

        $closingBalance = $balance;
        $totalDebit     = array_sum(array_column($rows, 'debit'));
        $totalCredit    = array_sum(array_column($rows, 'credit'));

        return Inertia::render('Reports/AccountLedger/Index', [
            'rows'           => array_values($rows),
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
            'totalDebit'     => $totalDebit,
            'totalCredit'    => $totalCredit,
            'account'        => $account,
            'accountName'    => self::ACCOUNTS[$account],
            'accounts'       => self::ACCOUNTS,
            'filters'        => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'account' => $account],
            'currentYear'    => $year,
        ]);
    }

    private function buildTK131(string $from, string $to, string $prev): array
    {
        // Opening: total invoiced - total payments received before period
        $invoicedBefore = (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled'])->where('issue_date', '<=', $prev)->sum('total');
        $paidBefore     = (float) DB::table('payments')->where('payment_date', '<=', $prev)->sum('amount');
        $opening        = $invoicedBefore - $paidBefore;

        $rows = [];

        // DR: Invoices in period
        $invs = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNotIn('invoices.status', ['cancelled'])
            ->whereBetween('invoices.issue_date', [$from, $to])
            ->select('invoices.issue_date as date', 'invoices.code as ref',
                     'customers.name as partner',
                     DB::raw("'Xuất hóa đơn' as description"),
                     'invoices.total as debit',
                     DB::raw('0 as credit'))
            ->get();

        foreach ($invs as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref, 'description' => $r->description . ': ' . $r->ref,
                       'partner' => $r->partner, 'debit' => (float)$r->debit, 'credit' => 0.0];
        }

        // CR: Payments in period
        $pmts = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereBetween('payments.payment_date', [$from, $to])
            ->select('payments.payment_date as date', 'invoices.code as ref',
                     'customers.name as partner',
                     DB::raw("'Thu tiền' as description"),
                     DB::raw('0 as debit'),
                     'payments.amount as credit')
            ->get();

        foreach ($pmts as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref, 'description' => $r->description . ' / HĐ: ' . $r->ref,
                       'partner' => $r->partner, 'debit' => 0.0, 'credit' => (float)$r->credit];
        }

        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

        return [$opening, $rows];
    }

    private function buildTK331(string $from, string $to, string $prev): array
    {
        // Opening: total purchases - total AP payments before period (credit-normal → positive = credit balance)
        $pisBefore  = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled'])->whereNotNull('invoice_date')
            ->where('invoice_date', '<=', $prev)->sum('total');
        $appBefore  = (float) DB::table('purchase_invoice_payments')
            ->where('payment_date', '<=', $prev)->sum('amount');
        $opening    = $pisBefore - $appBefore;

        $rows = [];

        // CR: Purchase invoices in period
        $piList = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNotIn('purchase_invoices.status', ['cancelled'])
            ->whereNotNull('purchase_invoices.invoice_date')
            ->whereBetween('purchase_invoices.invoice_date', [$from, $to])
            ->select('purchase_invoices.invoice_date as date', 'purchase_invoices.code as ref',
                     'suppliers.name as partner',
                     DB::raw("'Nhận HĐ mua' as description"),
                     DB::raw('0 as debit'),
                     'purchase_invoices.total as credit')
            ->get();

        foreach ($piList as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref, 'description' => $r->description . ': ' . $r->ref,
                       'partner' => $r->partner, 'debit' => 0.0, 'credit' => (float)$r->credit];
        }

        // DR: AP payments in period
        $appmts = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereBetween('purchase_invoice_payments.payment_date', [$from, $to])
            ->select('purchase_invoice_payments.payment_date as date',
                     'purchase_invoices.code as ref',
                     'suppliers.name as partner',
                     DB::raw("'Trả tiền NCC' as description"),
                     'purchase_invoice_payments.amount as debit',
                     DB::raw('0 as credit'))
            ->get();

        foreach ($appmts as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref, 'description' => $r->description . ' / HĐ: ' . $r->ref,
                       'partner' => $r->partner, 'debit' => (float)$r->debit, 'credit' => 0.0];
        }

        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

        return [$opening, $rows];
    }

    private function buildTK156(string $from, string $to, string $prev): array
    {
        // Opening: stock value before period
        $stockInBefore  = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'in')->where('stock_movements.created_at', '<=', $prev . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stockOutBefore = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'out')->where('stock_movements.created_at', '<=', $prev . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $opening = $stockInBefore - $stockOutBefore;

        $rows = [];

        // DR: Stock in
        $ins = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->where('stock_movements.type', 'in')
            ->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select(DB::raw("DATE(stock_movements.created_at) as date"),
                     DB::raw("'—' as ref"),
                     'products.name as partner',
                     'stock_movements.quantity',
                     DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0) as debit'),
                     DB::raw('0 as credit'))
            ->get();

        foreach ($ins as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref,
                       'description' => 'Nhập kho: ' . $r->partner . ' (x' . $r->quantity . ')',
                       'partner' => $r->partner, 'debit' => (float)$r->debit, 'credit' => 0.0];
        }

        // CR: Stock out
        $outs = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', 'out')
            ->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select(DB::raw("DATE(stock_movements.created_at) as date"),
                     DB::raw("'—' as ref"),
                     'products.name as partner',
                     'stock_movements.quantity',
                     DB::raw('0 as debit'),
                     DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0) as credit'))
            ->get();

        foreach ($outs as $r) {
            $rows[] = ['date' => $r->date, 'ref' => $r->ref,
                       'description' => 'Xuất kho: ' . $r->partner . ' (x' . $r->quantity . ')',
                       'partner' => $r->partner, 'debit' => 0.0, 'credit' => (float)$r->credit];
        }

        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

        return [$opening, $rows];
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new AccountLedgerExport($request->all()),
            'account-ledger-tk' . $request->input('account', '131') . '-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
