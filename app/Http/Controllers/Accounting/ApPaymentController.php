<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Supplier;
use App\Services\ArApLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApPaymentController extends Controller
{
    public function __construct(private ArApLedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['supplier_id', 'status']);

        $items = $this->ledger->payables($filters, onlyOutstanding: true)
            ->map(fn ($item) => [
                'id'           => $item['id'],
                'source_type'  => $item['source_type'],
                'code'         => $item['code'],
                'supplier_id'  => $item['partner_id'],
                'supplier'     => $item['partner_name'],
                'invoice_date' => $item['doc_date'] ? date('d/m/Y', strtotime($item['doc_date'])) : '—',
                'due_date'     => $item['due_date'] ? date('d/m/Y', strtotime($item['due_date'])) : null,
                'total'        => $item['total'],
                'amount_paid'  => $item['paid'],
                'amount_due'   => $item['remaining'],
                'status'       => $item['status'],
                'status_label' => $item['status_label'],
                'status_color' => $item['status_color'],
            ])
            ->values();

        return Inertia::render('Accounting/ApPayments/Index', [
            'items'     => $items,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'funds'     => Fund::where('is_active', true)->orderBy('type')->orderBy('name')
                ->get(['id', 'name', 'type', 'account_code']),
            'statuses'  => [
                ['value' => 'valid',        'label' => 'Hợp lệ / Chưa TT'],
                ['value' => 'partial_paid', 'label' => 'TT một phần'],
            ],
            'filters'   => $filters,
        ]);
    }
}
