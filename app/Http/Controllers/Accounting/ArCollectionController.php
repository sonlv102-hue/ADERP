<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ArApOpeningBalance;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArCollectionController extends Controller
{
    public function index(Request $request): Response
    {
        // ── Hóa đơn bán hàng chưa thu ──────────────────────────────────
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

        $invoiceItems = $query->get()->map(fn ($inv) => [
            'id'           => $inv->id,
            'source_type'  => 'invoice',
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
        ]);

        // ── Công nợ đầu kỳ AR (remaining_amount > 0) ───────────────────
        $obQuery = ArApOpeningBalance::with(['customer'])
            ->where('type', 'ar')
            ->where('remaining_amount', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id');

        if ($request->filled('customer_id')) {
            $obQuery->where('customer_id', $request->customer_id);
        }

        $today = now()->startOfDay();

        $openingItems = $obQuery->get()
            ->map(function ($ob) use ($today, $request) {
                $total     = (float) $ob->amount;
                $remaining = (float) $ob->remaining_amount;
                $amountPaid = max(0.0, round($total - $remaining, 2));
                $isOverdue  = $ob->due_date && $ob->due_date->lt($today);
                $status     = $isOverdue ? 'overdue' : 'sent';

                if ($request->filled('status') && $request->status !== $status) {
                    return null;
                }

                return [
                    'id'           => $ob->id,
                    'source_type'  => 'opening_balance',
                    'code'         => $ob->invoice_ref ?? ('OPENING-AR-' . $ob->id),
                    'customer_id'  => $ob->customer_id,
                    'customer'     => $ob->customer?->name ?? '—',
                    'issue_date'   => $ob->invoice_date?->format('d/m/Y') ?? '—',
                    'due_date'     => $ob->due_date?->format('d/m/Y'),
                    'total'        => $total,
                    'amount_paid'  => $amountPaid,
                    'amount_due'   => $remaining,
                    'status'       => $status,
                    'status_label' => $isOverdue ? 'Quá hạn (ĐK)' : 'Đầu kỳ',
                    'status_color' => $isOverdue ? 'red' : 'yellow',
                ];
            })
            ->filter()
            ->values();

        // Opening balances trước (chứng từ cũ hơn), invoices sau
        $items = $openingItems->concat($invoiceItems)->values();

        return Inertia::render('Accounting/ArCollections/Index', [
            'items'     => $items,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'code']),
            'statuses'  => [
                ['value' => 'sent',    'label' => 'Chưa thu'],
                ['value' => 'overdue', 'label' => 'Quá hạn'],
            ],
            'filters'   => $request->only(['customer_id', 'status']),
        ]);
    }
}
