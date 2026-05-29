<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\PurchaseInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PurchaseInvoice::with([
            'supplier' => fn ($q) => $q->withTrashed(),
            'payments',
        ])
        ->whereIn('status', [PurchaseInvoiceStatus::Valid, PurchaseInvoiceStatus::PartialPaid])
        ->orderBy('due_date')
        ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->get()->map(fn ($pi) => [
            'id'           => $pi->id,
            'code'         => $pi->code,
            'supplier_id'  => $pi->supplier_id,
            'supplier'     => $pi->supplier?->name ?? '—',
            'invoice_date' => $pi->invoice_date?->format('d/m/Y'),
            'due_date'     => $pi->due_date?->format('d/m/Y'),
            'total'        => (float) $pi->total,
            'amount_paid'  => (float) $pi->payments->sum('amount'),
            'amount_due'   => max(0, (float) $pi->total - (float) $pi->payments->sum('amount')),
            'status'       => $pi->status->value,
            'status_label' => $pi->status->label(),
            'status_color' => $pi->status->color(),
        ])->values();

        return Inertia::render('Accounting/ApPayments/Index', [
            'invoices'  => $invoices,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'statuses'  => [
                ['value' => 'valid',        'label' => PurchaseInvoiceStatus::Valid->label()],
                ['value' => 'partial_paid', 'label' => PurchaseInvoiceStatus::PartialPaid->label()],
            ],
            'filters'   => $request->only(['supplier_id', 'status']),
        ]);
    }
}
