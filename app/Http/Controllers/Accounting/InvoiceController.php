<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    public function index(Request $request): Response
    {
        $query = Invoice::with(['customer' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('code', 'ilike', "%{$q}%")
                   ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%"));
            });
        }

        return Inertia::render('Accounting/Invoices/Index', [
            'invoices' => $query->paginate(20)->through(fn ($inv) => [
                'id'           => $inv->id,
                'code'         => $inv->code,
                'customer'     => $inv->customer?->name ?? '—',
                'issue_date'   => $inv->issue_date->format('d/m/Y'),
                'due_date'     => $inv->due_date?->format('d/m/Y'),
                'total'        => (float) $inv->total,
                'status'       => $inv->status->value,
                'status_label' => $inv->status->label(),
                'status_color' => $inv->status->color(),
            ]),
            'filters'  => $request->only(['status', 'search']),
            'statuses' => collect(InvoiceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Invoices/Form', [
            'nextCode'  => Invoice::generateCode(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'orders'    => $this->ordersWithTotal(),
            'contracts' => Contract::orderByDesc('id')->get(['id', 'code', 'title', 'value', 'order_id']),
            'methods'   => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:20', 'unique:invoices,code'],
            'customer_id' => ['required', 'exists:customers,id'],
            'order_id'    => ['nullable', 'exists:orders,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'issue_date'  => ['required', 'date'],
            'due_date'    => ['nullable', 'date', 'after_or_equal:issue_date'],
            'subtotal'    => ['required', 'numeric', 'min:0'],
            'tax_amount'  => ['required', 'numeric', 'min:0'],
            'total'       => ['required', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string'],
        ]);

        $invoice = Invoice::create([...$data, 'created_by' => auth()->id()]);

        // Kiểm tra hạn mức công nợ khách hàng
        $warning = $this->checkCreditLimit($invoice->customer_id, (float) $invoice->total);

        return redirect()->route('accounting.invoices.show', $invoice)
            ->with('success', "Đã tạo hóa đơn {$invoice->code}")
            ->with('warning', $warning);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['customer' => fn ($q) => $q->withTrashed(), 'order', 'contract', 'creator', 'payments.creator']);

        return Inertia::render('Accounting/Invoices/Show', [
            'invoice' => [
                'id'           => $invoice->id,
                'code'         => $invoice->code,
                'customer'     => $invoice->customer ? ['id' => $invoice->customer->id, 'name' => $invoice->customer->name] : null,
                'order'        => $invoice->order ? ['id' => $invoice->order->id, 'code' => $invoice->order->code] : null,
                'contract'     => $invoice->contract ? ['id' => $invoice->contract->id, 'code' => $invoice->contract->code, 'title' => $invoice->contract->title] : null,
                'issue_date'   => $invoice->issue_date->format('d/m/Y'),
                'due_date'     => $invoice->due_date?->format('d/m/Y'),
                'subtotal'     => (float) $invoice->subtotal,
                'tax_amount'   => (float) $invoice->tax_amount,
                'total'        => (float) $invoice->total,
                'amount_paid'  => $invoice->amountPaid(),
                'amount_due'   => $invoice->amountDue(),
                'status'       => $invoice->status->value,
                'status_label' => $invoice->status->label(),
                'status_color' => $invoice->status->color(),
                'notes'              => $invoice->notes,
                'creator'            => $invoice->creator->name,
                'created_at'         => $invoice->created_at->format('d/m/Y H:i'),
                'e_inv_template'     => $invoice->e_inv_template,
                'e_inv_series'       => $invoice->e_inv_series,
                'e_inv_number'       => $invoice->e_inv_number,
                'e_inv_status'       => $invoice->e_inv_status,
                'e_inv_issued_at'    => $invoice->e_inv_issued_at?->format('d/m/Y H:i'),
                'e_inv_cancel_reason'=> $invoice->e_inv_cancel_reason,
                'payments'     => $invoice->payments->map(fn ($p) => [
                    'id'           => $p->id,
                    'amount'       => (float) $p->amount,
                    'payment_date' => $p->payment_date->format('d/m/Y'),
                    'method'       => $p->method->value,
                    'method_label' => $p->method->label(),
                    'reference'    => $p->reference,
                    'notes'        => $p->notes,
                    'creator'      => $p->creator->name,
                    'created_at'   => $p->created_at->format('d/m/Y H:i'),
                ]),
                'allowed_actions' => $this->allowedActions($invoice),
            ],
            'methods' => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
        ]);
    }

    public function edit(Invoice $invoice): Response
    {
        return Inertia::render('Accounting/Invoices/Form', [
            'invoice'   => $invoice,
            'nextCode'  => $invoice->code,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'orders'    => $this->ordersWithTotal(),
            'contracts' => Contract::orderByDesc('id')->get(['id', 'code', 'title', 'value', 'order_id']),
            'methods'   => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
        ]);
    }

    private function ordersWithTotal(): \Illuminate\Support\Collection
    {
        return Order::with('items:id,order_id,quantity,unit_price')
            ->orderByDesc('id')
            ->get(['id', 'code'])
            ->map(fn ($o) => [
                'id'    => $o->id,
                'code'  => $o->code,
                'total' => (float) $o->items->sum(fn ($i) => $i->quantity * $i->unit_price),
            ]);
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa hóa đơn ở trạng thái Nháp.');
        }

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'order_id'    => ['nullable', 'exists:orders,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'issue_date'  => ['required', 'date'],
            'due_date'    => ['nullable', 'date', 'after_or_equal:issue_date'],
            'subtotal'    => ['required', 'numeric', 'min:0'],
            'tax_amount'  => ['required', 'numeric', 'min:0'],
            'total'       => ['required', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string'],
        ]);

        $invoice->update($data);

        return redirect()->route('accounting.invoices.show', $invoice)
            ->with('success', 'Đã cập nhật hóa đơn.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return back()->with('error', 'Chỉ có thể xóa hóa đơn ở trạng thái Nháp. Hóa đơn đã gửi có bút toán kế toán liên quan.');
        }
        if ($invoice->payments()->exists()) {
            return back()->with('error', 'Không thể xóa hóa đơn đã có thanh toán.');
        }

        $invoice->delete();

        return redirect()->route('accounting.invoices.index')
            ->with('success', 'Đã xóa hóa đơn.');
    }

    public function markSent(Invoice $invoice): RedirectResponse
    {
        try {
            $this->service->markSent($invoice);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã gửi hóa đơn.');
    }

    public function markPaid(Invoice $invoice): RedirectResponse
    {
        try {
            $this->service->markPaid($invoice);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã đánh dấu thanh toán.');
    }

    public function markOverdue(Invoice $invoice): RedirectResponse
    {
        try {
            $this->service->markOverdue($invoice);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã đánh dấu quá hạn.');
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['customer', 'order', 'contract', 'creator', 'payments']);
        $pdf = Pdf::loadView('pdf.invoice', compact('invoice'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("HoaDon-{$invoice->code}.pdf");
    }

    public function issueEInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->e_inv_status === 'issued') {
            return back()->with('error', 'Hóa đơn đã được phát hành điện tử.');
        }

        $data = $request->validate([
            'e_inv_template' => 'required|string|max:30',
            'e_inv_series'   => 'required|string|max:30',
        ]);

        $invoice->e_inv_template = $data['e_inv_template'];
        $invoice->e_inv_series   = $data['e_inv_series'];
        $invoice->e_inv_number   = $invoice->nextEInvoiceNumber();
        $invoice->e_inv_status   = 'issued';
        $invoice->e_inv_issued_at = now();
        $invoice->save();

        return back()->with('success', "Đã phát hành HĐDT số {$invoice->e_inv_number}.");
    }

    public function cancelEInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->e_inv_status !== 'issued') {
            return back()->with('error', 'Chỉ hủy HĐDT đã phát hành.');
        }

        $data = $request->validate([
            'e_inv_cancel_reason' => 'required|string|max:500',
        ]);

        $invoice->update([
            'e_inv_status'        => 'cancelled',
            'e_inv_cancel_reason' => $data['e_inv_cancel_reason'],
        ]);

        return back()->with('success', 'Đã hủy hóa đơn điện tử.');
    }

    public function eInvoicePdf(Invoice $invoice)
    {
        $invoice->load(['customer', 'order', 'creator']);
        $company = \App\Models\Setting::getGroup('company');
        $pdf = Pdf::loadView('pdf.e-invoice', compact('invoice', 'company'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("HDDT-{$invoice->e_inv_series}-{$invoice->e_inv_number}.pdf");
    }

    private function allowedActions(Invoice $invoice): array
    {
        return match($invoice->status) {
            InvoiceStatus::Draft   => ['mark_sent', 'edit', 'delete'],
            InvoiceStatus::Sent    => ['mark_overdue', 'add_payment'],
            InvoiceStatus::Overdue => ['add_payment'],
            InvoiceStatus::Paid    => [],
        };
    }

    /**
     * Trả về cảnh báo (string) nếu tổng công nợ vượt hạn mức, null nếu OK.
     */
    private function checkCreditLimit(int $customerId, float $newInvoiceTotal): ?string
    {
        $customer = Customer::find($customerId);
        if (!$customer || !$customer->credit_limit || $customer->credit_limit <= 0) {
            return null;
        }

        // Tổng công nợ chưa thanh toán (Sent + Overdue)
        $outstanding = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue'])
            ->get()
            ->sum(fn ($inv) => max(0, (float) $inv->total - $inv->amountPaid()));

        $total = $outstanding + $newInvoiceTotal;
        $limit = (float) $customer->credit_limit;

        if ($total > $limit) {
            $fmt = fn ($v) => number_format($v, 0, ',', '.') . ' ₫';
            return "Cảnh báo hạn mức: Tổng công nợ {$customer->name} sau hóa đơn này là {$fmt($total)}, vượt hạn mức {$fmt($limit)}.";
        }

        return null;
    }
}
