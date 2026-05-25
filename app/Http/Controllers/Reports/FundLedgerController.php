<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FundLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $fundId   = $request->input('fund_id');
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());

        $funds = Fund::orderBy('type')->orderBy('name')->get(['id', 'name', 'type']);

        $entries  = [];
        $fund     = null;
        $balances = [];

        if ($fundId) {
            $fund    = Fund::findOrFail($fundId);
            $entries = $this->buildLedger($fund, $dateFrom, $dateTo);

            // Opening balance at start of period
            $openingBalance = $this->openingBalance($fund, $dateFrom);
            $running        = $openingBalance;
            foreach ($entries as &$e) {
                $running += $e['debit'] - $e['credit'];
                $e['balance'] = $running;
            }
            unset($e);

            $totalDebit  = array_sum(array_column($entries, 'debit'));
            $totalCredit = array_sum(array_column($entries, 'credit'));

            $balances = [
                'opening'      => $openingBalance,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'closing'      => $openingBalance + $totalDebit - $totalCredit,
            ];
        }

        return Inertia::render('Reports/FundLedger/Index', [
            'funds'    => $funds,
            'fund'     => $fund ? ['id' => $fund->id, 'name' => $fund->name, 'type' => $fund->type] : null,
            'entries'  => $entries,
            'balances' => $balances,
            'filters'  => ['fund_id' => $fundId, 'date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);
    }

    private function openingBalance(Fund $fund, string $dateFrom): float
    {
        $receipts = DB::table('cash_vouchers')
            ->where('fund_id', $fund->id)->where('type', 'receipt')->where('status', 'confirmed')
            ->where('voucher_date', '<', $dateFrom)->sum('amount');

        $payments = DB::table('cash_vouchers')
            ->where('fund_id', $fund->id)->where('type', 'payment')->where('status', 'confirmed')
            ->where('voucher_date', '<', $dateFrom)->sum('amount');

        $arReceived = DB::table('payments')
            ->where('fund_id', $fund->id)->where('payment_date', '<', $dateFrom)->sum('amount');

        $apPaid = DB::table('purchase_invoice_payments')
            ->where('fund_id', $fund->id)->where('payment_date', '<', $dateFrom)->sum('amount');

        return (float) $fund->opening_balance + $receipts - $payments + $arReceived - $apPaid;
    }

    private function buildLedger(Fund $fund, string $from, string $to): array
    {
        $entries = [];

        // Phiếu thu
        $receipts = DB::table('cash_vouchers')
            ->where('fund_id', $fund->id)->where('type', 'receipt')->where('status', 'confirmed')
            ->whereBetween('voucher_date', [$from, $to])
            ->orderBy('voucher_date')->orderBy('id')
            ->select('voucher_date as date', 'code as ref', 'description', 'counterparty', 'amount')
            ->get();

        foreach ($receipts as $r) {
            $entries[] = [
                'date'        => $r->date,
                'ref'         => $r->ref,
                'description' => $r->description,
                'counterparty'=> $r->counterparty,
                'debit'       => (float) $r->amount,
                'credit'      => 0.0,
                'balance'     => 0.0,
            ];
        }

        // Phiếu chi
        $payments = DB::table('cash_vouchers')
            ->where('fund_id', $fund->id)->where('type', 'payment')->where('status', 'confirmed')
            ->whereBetween('voucher_date', [$from, $to])
            ->orderBy('voucher_date')->orderBy('id')
            ->select('voucher_date as date', 'code as ref', 'description', 'counterparty', 'amount')
            ->get();

        foreach ($payments as $p) {
            $entries[] = [
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => $p->description,
                'counterparty'=> $p->counterparty,
                'debit'       => 0.0,
                'credit'      => (float) $p->amount,
                'balance'     => 0.0,
            ];
        }

        // Thu tiền KH (payments gắn quỹ này)
        $arPayments = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('payments.fund_id', $fund->id)
            ->whereBetween('payments.payment_date', [$from, $to])
            ->orderBy('payments.payment_date')->orderBy('payments.id')
            ->select('payments.payment_date as date', 'invoices.code as ref',
                     DB::raw("'Thu tiền KH / ' || invoices.code as description"),
                     'customers.name as counterparty', 'payments.amount')
            ->get();

        foreach ($arPayments as $p) {
            $entries[] = [
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => $p->description,
                'counterparty'=> $p->counterparty,
                'debit'       => (float) $p->amount,
                'credit'      => 0.0,
                'balance'     => 0.0,
            ];
        }

        // Thanh toán NCC (purchase_invoice_payments gắn quỹ này)
        $apPayments = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->where('purchase_invoice_payments.fund_id', $fund->id)
            ->whereBetween('purchase_invoice_payments.payment_date', [$from, $to])
            ->orderBy('purchase_invoice_payments.payment_date')->orderBy('purchase_invoice_payments.id')
            ->select('purchase_invoice_payments.payment_date as date', 'purchase_invoices.code as ref',
                     DB::raw("'Thanh toán NCC / ' || purchase_invoices.code as description"),
                     'suppliers.name as counterparty', 'purchase_invoice_payments.amount')
            ->get();

        foreach ($apPayments as $p) {
            $entries[] = [
                'date'        => $p->date,
                'ref'         => $p->ref,
                'description' => $p->description,
                'counterparty'=> $p->counterparty,
                'debit'       => 0.0,
                'credit'      => (float) $p->amount,
                'balance'     => 0.0,
            ];
        }

        usort($entries, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $entries;
    }
}
