<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DocumentChecklistController extends Controller
{
    public function index(Request $request): Response
    {
        $tab       = $request->input('tab', 'sales');
        $from      = $request->input('date_from', now()->startOfYear()->toDateString());
        $to        = $request->input('date_to', now()->toDateString());
        $partnerId = $request->input('partner_id');
        $missing   = $request->input('missing'); // filter: show only incomplete

        $sales     = $tab === 'sales'     ? $this->salesChecklist($from, $to, $partnerId, $missing)     : [];
        $purchases = $tab === 'purchases' ? $this->purchaseChecklist($from, $to, $partnerId, $missing)   : [];

        $customers = DB::table('customers')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'code']);
        $suppliers = DB::table('suppliers')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'code']);

        return Inertia::render('Reports/DocumentChecklist', [
            'tab'       => $tab,
            'sales'     => $sales,
            'purchases' => $purchases,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'filters'   => $request->only(['tab', 'date_from', 'date_to', 'partner_id', 'missing']),
        ]);
    }

    private function salesChecklist(string $from, string $to, ?string $customerId, ?string $missing): array
    {
        $rows = DB::table('orders as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as cnt FROM contracts GROUP BY order_id) as ct'),
                'ct.order_id', '=', 'o.id')
            ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as cnt FROM invoices GROUP BY order_id) as inv'),
                'inv.order_id', '=', 'o.id')
            ->leftJoin(DB::raw("(SELECT order_id, COUNT(*) as cnt FROM stock_exits WHERE status='confirmed' GROUP BY order_id) as se"),
                'se.order_id', '=', 'o.id')
            ->where('o.status', '!=', 'cancelled')
            ->whereBetween('o.order_date', [$from, $to])
            ->when($customerId, fn ($q) => $q->where('o.customer_id', $customerId))
            ->select([
                'o.id', 'o.code', 'o.order_date', 'o.status',
                'c.name as customer_name', 'c.is_fdi',
                'o.customs_status',
                DB::raw('COALESCE(ct.cnt,0) > 0 as has_contract'),
                DB::raw('COALESCE(inv.cnt,0) > 0 as has_invoice'),
                DB::raw('COALESCE(se.cnt,0) > 0 as has_stock_exit'),
            ])
            ->orderByDesc('o.order_date')
            ->orderByDesc('o.id')
            ->get();

        return $rows->map(function ($r) use ($missing) {
            $isFdi         = (bool) $r->is_fdi;
            $hasContract   = (bool) $r->has_contract;
            $hasInvoice    = (bool) $r->has_invoice;
            $hasStockExit  = (bool) $r->has_stock_exit;
            $hasCustoms    = !$isFdi || $r->customs_status === 'declared';

            $isComplete = $hasContract && $hasInvoice && $hasStockExit && $hasCustoms;

            if ($missing === '1' && $isComplete) return null;

            return [
                'id'           => $r->id,
                'code'         => $r->code,
                'partner'      => $r->customer_name,
                'is_fdi'       => $isFdi,
                'date'         => $r->order_date,
                'status'       => $r->status,
                'has_contract'  => $hasContract,
                'has_invoice'   => $hasInvoice,
                'has_stock_exit'=> $hasStockExit,
                'has_customs'   => $hasCustoms,
                'needs_customs' => $isFdi,
                'customs_status'=> $r->customs_status,
                'is_complete'   => $isComplete,
                'missing_docs'  => $this->missingDocsSales($hasContract, $hasInvoice, $hasStockExit, $isFdi, $hasCustoms),
            ];
        })->filter()->values()->toArray();
    }

    private function purchaseChecklist(string $from, string $to, ?string $supplierId, ?string $missing): array
    {
        $rows = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin(DB::raw('(SELECT purchase_order_id, COUNT(*) as cnt FROM purchase_contracts GROUP BY purchase_order_id) as pc'),
                'pc.purchase_order_id', '=', 'po.id')
            ->leftJoin(DB::raw('(SELECT purchase_order_id, COUNT(*) as cnt FROM purchase_invoices GROUP BY purchase_order_id) as pi'),
                'pi.purchase_order_id', '=', 'po.id')
            ->leftJoin(DB::raw("(SELECT purchase_order_id, COUNT(*) as cnt FROM stock_entries WHERE status='confirmed' GROUP BY purchase_order_id) as se"),
                'se.purchase_order_id', '=', 'po.id')
            ->where('po.status', '!=', 'cancelled')
            ->whereBetween('po.order_date', [$from, $to])
            ->when($supplierId, fn ($q) => $q->where('po.supplier_id', $supplierId))
            ->select([
                'po.id', 'po.code', 'po.order_date', 'po.status',
                's.name as supplier_name',
                DB::raw('COALESCE(pc.cnt,0) > 0 as has_contract'),
                DB::raw('COALESCE(pi.cnt,0) > 0 as has_invoice'),
                DB::raw('COALESCE(se.cnt,0) > 0 as has_stock_entry'),
            ])
            ->orderByDesc('po.order_date')
            ->orderByDesc('po.id')
            ->get();

        return $rows->map(function ($r) use ($missing) {
            $hasContract   = (bool) $r->has_contract;
            $hasInvoice    = (bool) $r->has_invoice;
            $hasStockEntry = (bool) $r->has_stock_entry;
            $isComplete    = $hasContract && $hasInvoice && $hasStockEntry;

            if ($missing === '1' && $isComplete) return null;

            return [
                'id'             => $r->id,
                'code'           => $r->code,
                'partner'        => $r->supplier_name,
                'date'           => $r->order_date,
                'status'         => $r->status,
                'has_contract'   => $hasContract,
                'has_invoice'    => $hasInvoice,
                'has_stock_entry'=> $hasStockEntry,
                'is_complete'    => $isComplete,
                'missing_docs'   => $this->missingDocsPurchase($hasContract, $hasInvoice, $hasStockEntry),
            ];
        })->filter()->values()->toArray();
    }

    private function missingDocsSales(bool $contract, bool $invoice, bool $exit, bool $isFdi, bool $customs): array
    {
        $missing = [];
        if (!$contract) $missing[] = 'Hợp đồng';
        if (!$invoice)  $missing[] = 'Hóa đơn';
        if (!$exit)     $missing[] = 'Phiếu xuất kho';
        if ($isFdi && !$customs) $missing[] = 'Tờ khai hải quan';
        return $missing;
    }

    private function missingDocsPurchase(bool $contract, bool $invoice, bool $entry): array
    {
        $missing = [];
        if (!$contract) $missing[] = 'Hợp đồng mua';
        if (!$invoice)  $missing[] = 'Hóa đơn NCC';
        if (!$entry)    $missing[] = 'Phiếu nhập kho';
        return $missing;
    }
}
