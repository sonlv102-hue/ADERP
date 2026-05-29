<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArCollectionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Invoice::with([
            'customer' => fn ($q) => $q->withTrashed(),
        ])
        ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Overdue])
        ->orderBy('due_date')
        ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->get()->map(fn ($inv) => [
            'id'           => $inv->id,
            'code'         => $inv->code,
            'customer_id'  => $inv->customer_id,
            'customer'     => $inv->customer?->name ?? '—',
            'issue_date'   => $inv->issue_date->format('d/m/Y'),
            'due_date'     => $inv->due_date?->format('d/m/Y'),
            'total'        => (float) $inv->total,
            'amount_paid'  => $inv->amountPaid(),
            'amount_due'   => $inv->amountDue(),
            'status'       => $inv->status->value,
            'status_label' => $inv->status->label(),
            'status_color' => $inv->status->color(),
        ])->values();

        return Inertia::render('Accounting/ArCollections/Index', [
            'invoices'  => $invoices,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'code']),
            'statuses'  => [
                ['value' => 'sent',    'label' => InvoiceStatus::Sent->label()],
                ['value' => 'overdue', 'label' => InvoiceStatus::Overdue->label()],
            ],
            'filters'   => $request->only(['customer_id', 'status']),
        ]);
    }
}
