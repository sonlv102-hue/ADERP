<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockExitStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Fund;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockExit;
use App\Services\CustomerAdvanceService;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $service,
        private CustomerAdvanceService $advanceService,
    ) {}

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
            'nextCode'        => Invoice::generateCode(),
            'orders'          => $this->ordersWithItems(),
            'contracts'       => Contract::orderByDesc('id')->get(['id', 'code', 'title', 'value', 'order_id']),
            'methods'         => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'revenueAccounts' => $this->revenueAccountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                 => ['required', 'string', 'max:20', 'unique:invoices,code'],
            'customer_id'          => ['required', 'exists:customers,id'],
            'order_id'             => ['nullable', 'exists:orders,id'],
            'contract_id'          => ['nullable', 'exists:contracts,id'],
            'issue_date'           => ['required', 'date'],
            'due_date'             => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes'                => ['nullable', 'string'],
            'revenue_account_code' => ['nullable', 'string', 'max:10', 'exists:account_codes,code'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.description'  => ['nullable', 'string', 'max:500'],
            'items.*.quantity'     => ['required', 'numeric', 'min:0'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'     => ['required', 'numeric', 'in:0,5,8,10'],
        ]);

        [$subtotal, $taxAmount] = $this->computeFromItems($data['items']);
        $total = $subtotal + $taxAmount;

        // Kiểm tra tổng hóa đơn không vượt giá trị đơn hàng
        if (!empty($data['order_id'])) {
            $order = Order::find($data['order_id']);
            if ($order) {
                $existingTotal = Invoice::where('order_id', $data['order_id'])
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)
                    ->sum('total');
                $orderTotal = $order->total();
                if ($existingTotal + $total > $orderTotal * 1.001) {
                    return back()
                        ->withErrors(['items' => "Tổng hóa đơn (" . number_format($existingTotal + $total, 0, ',', '.') . " ₫) vượt quá giá trị đơn hàng {$order->code} (" . number_format($orderTotal, 0, ',', '.') . " ₫)."])
                        ->withInput();
                }
            }
        }

        $invoice = DB::transaction(function () use ($data, $subtotal, $taxAmount, $total) {
            $inv = Invoice::create([
                'code'                 => $data['code'],
                'customer_id'          => $data['customer_id'],
                'order_id'             => $data['order_id'] ?? null,
                'contract_id'          => $data['contract_id'] ?? null,
                'issue_date'           => $data['issue_date'],
                'due_date'             => $data['due_date'] ?? null,
                'notes'                => $data['notes'] ?? null,
                'revenue_account_code' => $data['revenue_account_code'] ?? null,
                'subtotal'             => $subtotal,
                'tax_amount'           => $taxAmount,
                'total'                => $total,
                'created_by'           => auth()->id(),
            ]);
            $this->saveInvoiceItems($inv, $data['items']);
            return $inv;
        });

        // Kiểm tra hạn mức công nợ khách hàng
        $warning = $this->checkCreditLimit($invoice->customer_id, (float) $invoice->total);

        // Cảnh báo nếu dòng hàng trong đơn bán thiếu revenue_account_code
        if (!empty($data['order_id'])) {
            $missingCount = OrderItem::where('order_id', $data['order_id'])
                ->whereNull('revenue_account_code')
                ->count();
            if ($missingCount > 0) {
                $revWarning = "Có {$missingCount} dòng hàng chưa có tài khoản doanh thu — hệ thống tạm dùng TK 5111. Vui lòng cấu hình tại Catalog > Sản phẩm.";
                $warning = $warning ? "{$warning} | {$revWarning}" : $revWarning;
            }
        }

        return redirect()->route('accounting.invoices.show', $invoice)
            ->with('success', "Đã tạo hóa đơn {$invoice->code}")
            ->with('warning', $warning);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load([
            'customer' => fn ($q) => $q->withTrashed(),
            'order', 'contract', 'creator',
            'items',
            'payments.creator',
            'attachments.creator',
            'advanceAllocations' => fn ($q) => $q->with(['advance', 'creator'])->orderBy('allocation_date'),
        ]);

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
                'amount_paid'              => $invoice->amountPaid(),
                'advance_allocated_amount' => (float) ($invoice->advance_allocated_amount ?? 0),
                'amount_due'               => $invoice->amountDue(),
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
                'advance_allocations' => $invoice->advanceAllocations->map(fn ($a) => [
                    'id'              => $a->id,
                    'allocation_date' => $a->allocation_date->format('d/m/Y'),
                    'advance_ref'     => $a->advance?->reference_no ?? ('ADV-' . $a->opening_advance_id),
                    'allocated_amount'=> (float) $a->allocated_amount,
                    'reason'          => $a->reason,
                    'status'          => $a->status,
                    'creator'         => $a->creator?->name ?? '—',
                ]),
                'attachments'     => $invoice->attachments->map(fn ($a) => [
                    'id'         => $a->id,
                    'file_name'  => $a->file_name,
                    'file_url'   => \Illuminate\Support\Facades\Storage::disk('public')->url($a->file_path),
                    'file_size'  => $a->file_size,
                    'mime_type'  => $a->mime_type,
                    'created_by' => $a->creator->name,
                ]),
                'items'           => $invoice->items->sortBy('sort_order')->values()->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity'    => (float) $i->quantity,
                    'unit_price'  => (float) $i->unit_price,
                    'vat_rate'    => (float) $i->vat_rate,
                    'tax_amount'  => (int) $i->tax_amount,
                ])->all(),
                'allowed_actions' => $this->allowedActions($invoice),
                'cogs_status'     => $this->cogsStatus($invoice),
            ],
            'methods'            => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'funds'              => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'available_advances' => $this->advanceService->getAvailable($invoice->customer_id)->map(fn ($a) => [
                'id'               => $a->id,
                'reference_no'     => $a->reference_no,
                'type_label'       => $a->typeLabel(),
                'advance_date'     => $a->advance_date->format('d/m/Y'),
                'remaining_amount' => (float) $a->remaining_amount,
            ]),
        ]);
    }

    public function edit(Invoice $invoice): Response
    {
        $invoice->load(['items', 'customer']);
        return Inertia::render('Accounting/Invoices/Form', [
            'invoice'         => array_merge($invoice->toArray(), [
                'customer_name' => $invoice->customer?->name,
                'items' => $invoice->items->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity'    => (float) $i->quantity,
                    'unit_price'  => (float) $i->unit_price,
                    'vat_rate'    => (float) $i->vat_rate,
                    'tax_amount'  => $i->tax_amount,
                ])->values()->all(),
            ]),
            'nextCode'        => $invoice->code,
            'orders'          => $this->ordersWithItems(),
            'contracts'       => Contract::orderByDesc('id')->get(['id', 'code', 'title', 'value', 'order_id']),
            'methods'         => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'revenueAccounts' => $this->revenueAccountOptions(),
        ]);
    }

    private function revenueAccountOptions(): array
    {
        return AccountCode::where('type', 'revenue')
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    private function ordersWithItems(): \Illuminate\Support\Collection
    {
        return Order::with('items:id,order_id,name,quantity,unit_price,vat_rate')
            ->orderByDesc('id')
            ->get(['id', 'code'])
            ->map(fn ($o) => [
                'id'    => $o->id,
                'code'  => $o->code,
                'total' => (float) $o->items->sum(fn ($i) => $i->quantity * $i->unit_price),
                'items' => $o->items->map(fn ($i) => [
                    'description' => $i->name,
                    'quantity'    => (float) $i->quantity,
                    'unit_price'  => (float) $i->unit_price,
                    'vat_rate'    => (float) ($i->vat_rate ?? 0),
                    'tax_amount'  => (int) round((float) $i->quantity * (float) $i->unit_price * (float) ($i->vat_rate ?? 0) / 100),
                ])->values()->all(),
            ]);
    }

    // Server tính lại tax_amount từng dòng rồi cộng — không tin số client gửi lên.
    // Trả về [subtotal, taxTotal].
    private function computeFromItems(array $items): array
    {
        $subtotal = 0.0;
        $taxTotal = 0;
        foreach ($items as $item) {
            $lineSubtotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $lineTax      = (int) round($lineSubtotal * (float) $item['vat_rate'] / 100);
            $subtotal    += $lineSubtotal;
            $taxTotal    += $lineTax;
        }
        return [$subtotal, $taxTotal];
    }

    private function saveInvoiceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();
        foreach ($items as $sort => $item) {
            $lineSubtotal = (float) $item['quantity'] * (float) $item['unit_price'];
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'sort_order'  => $sort,
                'description' => $item['description'] ?? null,
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'vat_rate'    => $item['vat_rate'],
                'tax_amount'  => (int) round($lineSubtotal * (float) $item['vat_rate'] / 100),
            ]);
        }
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return back()->with('error', 'Chỉ có thể sửa hóa đơn ở trạng thái Nháp.');
        }

        $data = $request->validate([
            'customer_id'          => ['required', 'exists:customers,id'],
            'order_id'             => ['nullable', 'exists:orders,id'],
            'contract_id'          => ['nullable', 'exists:contracts,id'],
            'issue_date'           => ['required', 'date'],
            'due_date'             => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes'                => ['nullable', 'string'],
            'revenue_account_code' => ['nullable', 'string', 'max:10', 'exists:account_codes,code'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.description'  => ['nullable', 'string', 'max:500'],
            'items.*.quantity'     => ['required', 'numeric', 'min:0'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
            'items.*.vat_rate'     => ['required', 'numeric', 'in:0,5,8,10'],
        ]);

        [$subtotal, $taxAmount] = $this->computeFromItems($data['items']);

        DB::transaction(function () use ($invoice, $data, $subtotal, $taxAmount) {
            $invoice->update([
                'customer_id'          => $data['customer_id'],
                'order_id'             => $data['order_id'] ?? null,
                'contract_id'          => $data['contract_id'] ?? null,
                'issue_date'           => $data['issue_date'],
                'due_date'             => $data['due_date'] ?? null,
                'notes'                => $data['notes'] ?? null,
                'revenue_account_code' => $data['revenue_account_code'] ?? null,
                'subtotal'             => $subtotal,
                'tax_amount'           => $taxAmount,
                'total'                => $subtotal + $taxAmount,
            ]);
            $this->saveInvoiceItems($invoice, $data['items']);
        });

        return redirect()->route('accounting.invoices.show', $invoice)
            ->with('success', 'Đã cập nhật hóa đơn.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        if (!in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa hóa đơn ở trạng thái Nháp hoặc Đã hủy.');
        }
        if ($invoice->payments()->exists()) {
            return back()->with('error', 'Không thể xóa hóa đơn đã có thanh toán.');
        }

        $invoice->delete();

        return redirect()->route('accounting.invoices.index')
            ->with('success', 'Đã xóa hóa đơn.');
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        try {
            $this->service->cancel($invoice);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy hóa đơn.');
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
        $invoice->load(['customer', 'order', 'contract', 'creator', 'payments', 'items']);
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
        $invoice->load(['customer', 'order', 'creator', 'items']);
        $company = \App\Models\Setting::getGroup('company');
        $pdf = Pdf::loadView('pdf.e-invoice', compact('invoice', 'company'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("HDDT-{$invoice->e_inv_series}-{$invoice->e_inv_number}.pdf");
    }

    /**
     * Trả về trạng thái COGS của invoice:
     * - not_applicable: draft/cancelled, standalone, hoặc order không có dòng sản phẩm tồn kho
     * - cogs_missing:   order có sản phẩm tồn kho nhưng chưa có confirmed exit + JE 63x posted
     * - cogs_ok:        có confirmed exit VÀ JE 63x posted
     */
    private function cogsStatus(Invoice $invoice): string
    {
        if (!in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Paid])) {
            return 'not_applicable';
        }
        if (!$invoice->order_id) {
            return 'not_applicable';
        }

        $hasInventoryItems = DB::table('order_items')
            ->where('order_id', $invoice->order_id)
            ->whereNotNull('product_id')
            ->exists();

        if (!$hasInventoryItems) {
            return 'not_applicable';
        }

        $confirmedExitIds = StockExit::where('order_id', $invoice->order_id)
            ->where('issue_purpose', 'sale_delivery')
            ->where('status', StockExitStatus::Confirmed)
            ->pluck('id');

        if ($confirmedExitIds->isEmpty()) {
            return 'cogs_missing';
        }

        $hasCogs = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.reference_type', 'stock_exit')
            ->whereIn('je.reference_id', $confirmedExitIds)
            ->where('je.status', 'posted')
            ->where('jl.debit', '>', 0)
            ->whereRaw("jl.account_code LIKE '63%'")
            ->exists();

        return $hasCogs ? 'cogs_ok' : 'cogs_missing';
    }

    private function allowedActions(Invoice $invoice): array
    {
        return match($invoice->status) {
            InvoiceStatus::Draft     => ['mark_sent', 'edit', 'delete'],
            InvoiceStatus::Sent      => ['mark_overdue', 'add_payment', 'cancel'],
            InvoiceStatus::Overdue   => ['add_payment', 'cancel'],
            InvoiceStatus::Paid      => [],
            InvoiceStatus::Cancelled => [],
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

    public function exportExcel(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SalesInvoicesExport($request->all()),
            'hoa-don-ban_' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}
