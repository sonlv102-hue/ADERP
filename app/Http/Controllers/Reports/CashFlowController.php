<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Exports\Reports\CashFlowExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashFlowController extends Controller
{
    public function index(Request $request): Response
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());
        $method   = $request->input('method');
        $type     = $request->input('type'); // 'in' | 'out'

        // Thu (từ thanh toán hóa đơn bán)
        $inflow = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select([
                'payments.id',
                'payments.payment_date as date',
                DB::raw("'in' as type"),
                'invoices.code as ref_code',
                DB::raw("CONCAT('Thu HĐ ', invoices.code, ' - ', customers.name) as description"),
                'payments.method',
                'payments.amount',
                DB::raw('0 as outflow'),
            ])
            ->whereBetween('payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('payments.method', $method));

        // Chi (từ thanh toán hóa đơn mua)
        $outflow = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->select([
                'purchase_invoice_payments.id',
                'purchase_invoice_payments.payment_date as date',
                DB::raw("'out' as type"),
                'purchase_invoices.code as ref_code',
                DB::raw("CONCAT('Trả HĐ ', purchase_invoices.code, ' - ', suppliers.name) as description"),
                'purchase_invoice_payments.method',
                'purchase_invoice_payments.amount',
                DB::raw('0 as outflow'),
            ])
            ->whereBetween('purchase_invoice_payments.payment_date', [$dateFrom, $dateTo])
            ->when($method, fn ($q) => $q->where('purchase_invoice_payments.method', $method));

        // UNION và lọc theo type
        $allRows = collect();

        if ($type !== 'out') {
            $allRows = $allRows->merge($inflow->get()->map(fn ($r) => (array) $r));
        }
        if ($type !== 'in') {
            $allRows = $allRows->merge($outflow->get()->map(fn ($r) => (array) $r));
        }

        $allRows = $allRows->sortBy('date')->values();

        // Tính số dư lũy kế
        $balance = 0;
        $rows = $allRows->map(function ($row) use (&$balance) {
            $amount = (float) $row['amount'];
            $delta  = $row['type'] === 'in' ? $amount : -$amount;
            $balance += $delta;
            return array_merge($row, ['balance' => $balance]);
        });

        // Phân trang thủ công
        $perPage = 50;
        $page    = $request->input('page', 1);
        $total   = $rows->count();
        $slice   = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $summary = [
            'total_in'      => $allRows->where('type', 'in')->sum('amount'),
            'total_out'     => $allRows->where('type', 'out')->sum('amount'),
            'net_cash_flow' => $allRows->where('type', 'in')->sum('amount') - $allRows->where('type', 'out')->sum('amount'),
        ];

        return Inertia::render('Reports/CashFlow/Index', [
            'rows'    => [
                'data'         => $slice,
                'current_page' => (int) $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
            ],
            'summary' => $summary,
            'filters' => $request->only(['date_from', 'date_to', 'method', 'type']),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new CashFlowExport($request->all()),
            'cash-flow-' . now()->format('Ymd') . '.xlsx'
        );
    }
}
