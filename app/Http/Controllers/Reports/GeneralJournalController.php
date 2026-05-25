<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\GeneralJournalExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneralJournalController extends Controller
{
    public function index(Request $request): Response
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";
        $perPage  = 50;
        $page     = max(1, (int) $request->input('page', 1));

        $entries = $this->buildEntries($dateFrom, $dateTo);

        $totalDebit  = array_sum(array_column($entries, 'amount'));
        $totalCredit = $totalDebit; // journal always balanced

        $total  = count($entries);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($entries, $offset, $perPage);

        return Inertia::render('Reports/GeneralJournal/Index', [
            'entries'     => array_values($paged),
            'total'       => $total,
            'totalDebit'  => $totalDebit,
            'totalCredit' => $totalCredit,
            'currentPage' => $page,
            'lastPage'    => (int) ceil($total / $perPage),
            'filters'     => ['year' => $year, 'date_from' => $dateFrom, 'date_to' => $dateTo],
            'currentYear' => $year,
        ]);
    }

    private function buildEntries(string $from, string $to): array
    {
        $entries = [];
        $seq     = 1;

        // 1. Hóa đơn bán hàng → DR 131 / CR 511 + CR 3331
        $invoices = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->whereBetween('invoices.issue_date', [$from, $to])
            ->select('invoices.issue_date as date', 'invoices.code as ref',
                     'customers.name as partner', 'invoices.subtotal', 'invoices.tax_amount', 'invoices.total')
            ->orderBy('invoices.issue_date')
            ->get();

        foreach ($invoices as $inv) {
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $inv->date,
                'ref'         => $inv->ref,
                'description' => 'Xuất hóa đơn bán: ' . $inv->ref,
                'partner'     => $inv->partner,
                'debit_tk'    => '131',
                'credit_tk'   => '511' . ($inv->tax_amount > 0 ? ' / 3331' : ''),
                'amount'      => (float) $inv->total,
            ];
        }

        // 2. Thu tiền KH → DR 112 / CR 131
        $payments = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereBetween('payments.payment_date', [$from, $to])
            ->select('payments.payment_date as date', 'invoices.code as ref',
                     'customers.name as partner', 'payments.amount')
            ->orderBy('payments.payment_date')
            ->get();

        foreach ($payments as $p) {
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => 'Thu tiền KH / HĐ: ' . $p->ref,
                'partner'     => $p->partner,
                'debit_tk'    => '112',
                'credit_tk'   => '131',
                'amount'      => (float) $p->amount,
            ];
        }

        // 3. Hóa đơn mua hàng → DR 156 + DR 133 / CR 331
        $piList = DB::table('purchase_invoices')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereNotNull('purchase_invoices.invoice_date')
            ->whereNotIn('purchase_invoices.status', ['cancelled'])
            ->whereBetween('purchase_invoices.invoice_date', [$from, $to])
            ->select('purchase_invoices.invoice_date as date', 'purchase_invoices.code as ref',
                     'suppliers.name as partner',
                     'purchase_invoices.subtotal', 'purchase_invoices.tax_amount', 'purchase_invoices.total')
            ->orderBy('purchase_invoices.invoice_date')
            ->get();

        foreach ($piList as $pi) {
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $pi->date,
                'ref'         => $pi->ref,
                'description' => 'Nhận hóa đơn mua: ' . $pi->ref,
                'partner'     => $pi->partner,
                'debit_tk'    => '156' . ($pi->tax_amount > 0 ? ' / 133' : ''),
                'credit_tk'   => '331',
                'amount'      => (float) $pi->total,
            ];
        }

        // 4. Thanh toán NCC → DR 331 / CR 112
        $apPayments = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->whereBetween('purchase_invoice_payments.payment_date', [$from, $to])
            ->select('purchase_invoice_payments.payment_date as date',
                     'purchase_invoices.code as ref',
                     'suppliers.name as partner',
                     'purchase_invoice_payments.amount')
            ->orderBy('purchase_invoice_payments.payment_date')
            ->get();

        foreach ($apPayments as $p) {
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => 'Thanh toán NCC / HĐ: ' . $p->ref,
                'partner'     => $p->partner,
                'debit_tk'    => '331',
                'credit_tk'   => '112',
                'amount'      => (float) $p->amount,
            ];
        }

        // 5. Phiếu thu tiền mặt → DR 111/112 / CR tùy đối tượng
        $receipts = DB::table('cash_vouchers')
            ->join('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->where('cash_vouchers.type', 'receipt')
            ->where('cash_vouchers.status', 'confirmed')
            ->whereBetween('cash_vouchers.voucher_date', [$from, $to])
            ->select('cash_vouchers.voucher_date as date', 'cash_vouchers.code as ref',
                     'cash_vouchers.description', 'cash_vouchers.counterparty as partner',
                     'funds.type as fund_type', 'cash_vouchers.amount')
            ->orderBy('cash_vouchers.voucher_date')
            ->get();

        foreach ($receipts as $r) {
            $debitTk = $r->fund_type === 'cash' ? '111' : '112';
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $r->date,
                'ref'         => $r->ref,
                'description' => $r->description,
                'partner'     => $r->partner ?? '',
                'debit_tk'    => $debitTk,
                'credit_tk'   => '—',
                'amount'      => (float) $r->amount,
            ];
        }

        // 6. Phiếu chi tiền mặt → CR 111/112 / DR tùy đối tượng
        $cashPayments = DB::table('cash_vouchers')
            ->join('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->where('cash_vouchers.type', 'payment')
            ->where('cash_vouchers.status', 'confirmed')
            ->whereBetween('cash_vouchers.voucher_date', [$from, $to])
            ->select('cash_vouchers.voucher_date as date', 'cash_vouchers.code as ref',
                     'cash_vouchers.description', 'cash_vouchers.counterparty as partner',
                     'funds.type as fund_type', 'cash_vouchers.amount')
            ->orderBy('cash_vouchers.voucher_date')
            ->get();

        foreach ($cashPayments as $p) {
            $creditTk = $p->fund_type === 'cash' ? '111' : '112';
            $entries[] = [
                'seq'         => $seq++,
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => $p->description,
                'partner'     => $p->partner ?? '',
                'debit_tk'    => '—',
                'credit_tk'   => $creditTk,
                'amount'      => (float) $p->amount,
            ];
        }

        // 7. Nhập kho (không qua mua hàng) → DR 156 / CR 331
        $stockIns = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->where('stock_movements.type', 'in')
            ->whereNull('stock_movements.source_type')  // not linked to any source
            ->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select('stock_movements.created_at as date',
                     'products.name as product_name',
                     'stock_movements.quantity',
                     DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0) as amount'))
            ->orderBy('stock_movements.created_at')
            ->get();

        foreach ($stockIns as $sm) {
            if ($sm->amount <= 0) continue;
            $entries[] = [
                'seq'         => $seq++,
                'date'        => date('Y-m-d', strtotime($sm->date)),
                'ref'         => '—',
                'description' => 'Nhập kho: ' . $sm->product_name . ' (x' . $sm->quantity . ')',
                'partner'     => '',
                'debit_tk'    => '156',
                'credit_tk'   => '331',
                'amount'      => (float) $sm->amount,
            ];
        }

        // 6. Xuất kho → DR 632 / CR 156
        $stockOuts = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.type', 'out')
            ->whereBetween('stock_movements.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select('stock_movements.created_at as date',
                     'products.name as product_name',
                     'stock_movements.quantity',
                     DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0) as amount'))
            ->orderBy('stock_movements.created_at')
            ->get();

        foreach ($stockOuts as $sm) {
            if ($sm->amount <= 0) continue;
            $entries[] = [
                'seq'         => $seq++,
                'date'        => date('Y-m-d', strtotime($sm->date)),
                'ref'         => '—',
                'description' => 'Xuất kho / giá vốn: ' . $sm->product_name . ' (x' . $sm->quantity . ')',
                'partner'     => '',
                'debit_tk'    => '632',
                'credit_tk'   => '156',
                'amount'      => (float) $sm->amount,
            ];
        }

        // Sort all entries by date then seq
        usort($entries, fn($a, $b) => strcmp($a['date'] . $a['seq'], $b['date'] . $b['seq']));

        // Re-number after sort
        foreach ($entries as $i => &$e) {
            $e['seq'] = $i + 1;
        }

        return $entries;
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new GeneralJournalExport($request->all()),
            'general-journal-' . $request->input('year', now()->year) . '.xlsx'
        );
    }
}
