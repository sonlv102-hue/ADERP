<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceService;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private PurchaseInvoiceService $service,
        private SupplierAdvanceService $advanceService,
    ) {}

    public function index(): Response
    {
        $search = request('search');
        $status = request('status');

        return Inertia::render('Purchasing/PurchaseInvoices/Index', [
            'invoices' => PurchaseInvoice::with(['supplier', 'purchaseOrder', 'creator', 'payments.creator'])
                ->when($search, fn ($q) => $q->where(fn ($q2) =>
                    $q2->where('code', 'ilike', "%{$search}%")
                       ->orWhere('invoice_number', 'ilike', "%{$search}%")
                       ->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', "%{$search}%"))
                ))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($inv) => [
                    'id'             => $inv->id,
                    'code'           => $inv->code,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date'   => $inv->invoice_date?->format('d/m/Y'),
                    'due_date'       => $inv->due_date?->format('d/m/Y'),
                    'status'         => $inv->status->value,
                    'status_label'   => $inv->status->label(),
                    'status_color'   => $inv->status->color(),
                    'supplier'       => $inv->supplier->name,
                    'supplier_id'    => $inv->supplier_id,
                    'purchase_order' => $inv->purchaseOrder->code,
                    'total'          => $inv->total,
                    'paid_amount'    => $inv->paid_amount,
                    'remaining'      => $inv->remaining,
                    'creator'        => $inv->creator->name,
                    'payments'       => $inv->payments->map(fn ($p) => [
                        'id'           => $p->id,
                        'amount'       => (float) $p->amount,
                        'payment_date' => $p->payment_date->format('d/m/Y'),
                        'method_label' => match($p->method) {
                            'cash'          => 'Tiền mặt',
                            'bank_transfer' => 'Chuyển khoản',
                            default         => 'Khác',
                        },
                        'reference'    => $p->reference,
                        'creator'      => $p->creator->name,
                        'status'       => $p->status,
                    ]),
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Purchasing/PurchaseInvoices/Form', [
            'nextCode'       => PurchaseInvoice::generateCode(),
            'purchaseOrders' => PurchaseOrder::with(['supplier', 'items.product'])
                ->whereIn('status', ['sent', 'received'])
                ->orderByDesc('id')
                ->get()
                ->map(fn ($po) => [
                    'id'          => $po->id,
                    'code'        => $po->code,
                    'supplier_id' => $po->supplier_id,
                    'supplier'    => $po->supplier->name,
                    // unit_price đã gồm VAT — back-calculate để tách subtotal và tax
                    'subtotal'    => $po->items->sum(fn ($i) =>
                        $i->quantity * $i->unit_price / (1 + (($i->product?->vat_percent ?? 0) / 100))
                    ),
                    'tax_amount'  => $po->items->sum(fn ($i) =>
                        $i->quantity * $i->unit_price
                        - $i->quantity * $i->unit_price / (1 + (($i->product?->vat_percent ?? 0) / 100))
                    ),
                    'default_invoice_type' => $this->detectInvoiceType($po->items),
                ]),
            'suppliers'        => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'tax_code']),
            'selectedOrderId'  => $request->input('purchase_order_id'),
            'expenseAccounts'  => $this->expenseAccountList(),
            'invoiceTypes'     => $this->invoiceTypeList(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                 => ['required', 'string', 'unique:purchase_invoices,code'],
            'purchase_order_id'    => ['nullable', 'exists:purchase_orders,id'],
            'supplier_id'          => ['required', 'exists:suppliers,id'],
            'invoice_number'       => ['nullable', 'string', 'max:100'],
            'invoice_date'         => ['nullable', 'date'],
            'supplier_tax_code'    => ['nullable', 'string', 'max:50'],
            'subtotal'             => ['required', 'numeric', 'min:0'],
            'tax_amount'           => ['required', 'numeric', 'min:0'],
            'total'                => ['required', 'numeric', 'min:0'],
            'due_date'             => ['nullable', 'date'],
            'notes'                => ['nullable', 'string'],
            'expense_account_code' => ['nullable', 'string', 'max:20'],
            'invoice_type'         => ['nullable', 'string', 'in:' . implode(',', array_column(PurchaseInvoiceType::cases(), 'value'))],
        ]);

        $invoice = PurchaseInvoice::create([
            ...$data,
            'paid_amount' => 0,
            'status'      => PurchaseInvoiceStatus::Pending,
            'created_by'  => auth()->id(),
        ]);

        return redirect()->route('purchasing.purchase-invoices.show', $invoice)
            ->with('success', 'Đã tạo hóa đơn đầu vào.');
    }

    public function show(PurchaseInvoice $purchaseInvoice): Response
    {
        $purchaseInvoice->load([
            'supplier', 'purchaseOrder.items.product', 'creator',
            'payments.creator', 'attachments.creator',
            'activeAdvanceAllocations.advance', 'activeAdvanceAllocations.creator',
        ]);

        $pj = \App\Models\AccountingPostingJob::where('source_type', 'purchase_invoice')
            ->where('source_id', $purchaseInvoice->id)
            ->where('posting_type', 'ap')
            ->first();

        // Phân loại: goods nếu PO có items hàng hóa/NVL/CCDC (không phải service/fixed_asset)
        $isGoodsPurchase = $this->service->isGoodsPurchase($purchaseInvoice);

        // PO items để hiển thị loại dòng hàng
        $poItems = $purchaseInvoice->purchaseOrder?->items->map(fn ($item) => [
            'id'         => $item->id,
            'product'    => $item->product?->name ?? '—',
            'product_id' => $item->product_id,
            'quantity'   => $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'vat_rate'   => (float) $item->vat_rate,
            'line_type'  => $item->line_type ?? 'goods',
        ]) ?? collect();

        return Inertia::render('Purchasing/PurchaseInvoices/Show', [
            'invoice' => [
                'id'                => $purchaseInvoice->id,
                'code'              => $purchaseInvoice->code,
                'invoice_number'    => $purchaseInvoice->invoice_number,
                'invoice_date'      => $purchaseInvoice->invoice_date?->format('d/m/Y'),
                'due_date'          => $purchaseInvoice->due_date?->format('d/m/Y'),
                'supplier_tax_code' => $purchaseInvoice->supplier_tax_code,
                'status'            => $purchaseInvoice->status->value,
                'status_label'      => $purchaseInvoice->status->label(),
                'status_color'      => $purchaseInvoice->status->color(),
                'supplier'          => $purchaseInvoice->supplier->name,
                'purchase_order_id' => $purchaseInvoice->purchase_order_id,
                'purchase_order'    => $purchaseInvoice->purchaseOrder?->code,
                'subtotal'          => $purchaseInvoice->subtotal,
                'tax_amount'        => $purchaseInvoice->tax_amount,
                'total'             => $purchaseInvoice->total,
                'paid_amount'              => $purchaseInvoice->paid_amount,
                'advance_allocated_amount' => $purchaseInvoice->advance_allocated_amount,
                'remaining'                => $purchaseInvoice->remaining,
                'notes'                => $purchaseInvoice->notes,
                'expense_account_code' => $purchaseInvoice->expense_account_code,
                'invoice_type'         => $purchaseInvoice->invoice_type?->value,
                'invoice_type_label'   => $purchaseInvoice->invoice_type?->label(),
                'attachments' => $purchaseInvoice->attachments->map(fn ($a) => [
                    'id'        => $a->id,
                    'file_name' => $a->file_name,
                    'file_url'  => Storage::disk('public')->url($a->file_path),
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                    'created_by'=> $a->creator->name,
                ]),
                'creator'           => $purchaseInvoice->creator->name,
                'payments'          => $purchaseInvoice->payments->map(fn ($p) => [
                    'id'           => $p->id,
                    'amount'       => (float) $p->amount,
                    'payment_date' => $p->payment_date->format('d/m/Y'),
                    'method'       => $p->method,
                    'method_label' => match($p->method) {
                        'cash'          => 'Tiền mặt',
                        'bank_transfer' => 'Chuyển khoản',
                        default         => 'Khác',
                    },
                    'reference'   => $p->reference,
                    'notes'       => $p->notes,
                    'creator'     => $p->creator->name,
                    'status'      => $p->status,
                    'void_reason' => $p->void_reason,
                    'voided_at'   => $p->voided_at?->format('d/m/Y H:i'),
                ]),
                'transitions'         => $this->allowedTransitions($purchaseInvoice->status),
                'is_goods_purchase'   => $isGoodsPurchase,
                // Giữ is_service_purchase cho backwards-compat (= !isGoods khi có PO)
                'is_service_purchase' => !$isGoodsPurchase && $purchaseInvoice->purchase_order_id !== null,
                'po_items'             => $poItems,
                'advance_allocations'  => $purchaseInvoice->activeAdvanceAllocations->map(fn ($a) => [
                    'id'               => $a->id,
                    'allocation_date'  => $a->allocation_date->format('d/m/Y'),
                    'allocated_amount' => (float) $a->allocated_amount,
                    'reason'           => $a->reason,
                    'creator'          => $a->creator->name,
                    'advance_ref'      => $a->advance->reference_no ?? ('ADV-' . $a->opening_advance_id),
                ]),
                'posting_job'         => $pj ? [
                    'status'        => $pj->status->value,
                    'status_label'  => $pj->status->label(),
                    'error_message' => $pj->error_message,
                    'job_id'        => $pj->id,
                ] : null,
            ],
            'available_advances' => $this->advanceService
                ->getAvailable($purchaseInvoice->supplier_id)
                ->map(fn ($adv) => [
                    'id'               => $adv->id,
                    'reference_no'     => $adv->reference_no,
                    'opening_date'     => $adv->opening_date->format('d/m/Y'),
                    'amount'           => (float) $adv->amount,
                    'remaining_amount' => (float) $adv->remaining_amount,
                    'fiscal_year'      => $adv->fiscal_year,
                    'status'           => $adv->status,
                    'status_label'     => $adv->statusLabel(),
                ]),
        ]);
    }

    public function updateItemLineType(Request $request, PurchaseInvoice $purchaseInvoice, \App\Models\PurchaseOrderItem $item): \Illuminate\Http\JsonResponse
    {
        // Verify item belongs to this invoice's PO
        if ($item->purchase_order_id !== $purchaseInvoice->purchase_order_id) {
            abort(403, 'Item không thuộc đơn mua hàng của hóa đơn này.');
        }

        $data = $request->validate([
            'line_type' => ['required', 'in:goods,material,tool,service,fixed_asset'],
        ]);

        $item->update(['line_type' => $data['line_type']]);

        return response()->json(['ok' => true, 'line_type' => $item->line_type]);
    }

    public function edit(PurchaseInvoice $purchaseInvoice): Response
    {
        return Inertia::render('Purchasing/PurchaseInvoices/Form', [
            'invoice'        => $purchaseInvoice,
            'purchaseOrders' => PurchaseOrder::with(['supplier', 'items'])
                ->where(fn ($q) => $q->whereIn('status', ['sent', 'received'])
                    ->orWhere('id', $purchaseInvoice->purchase_order_id))
                ->orderByDesc('id')
                ->get()
                ->map(fn ($po) => [
                    'id'                   => $po->id,
                    'code'                 => $po->code,
                    'supplier_id'          => $po->supplier_id,
                    'supplier'             => $po->supplier->name,
                    'default_invoice_type' => $this->detectInvoiceType($po->items),
                ]),
            'suppliers'       => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'tax_code']),
            'expenseAccounts' => $this->expenseAccountList(),
            'invoiceTypes'    => $this->invoiceTypeList(),
        ]);
    }

    public function update(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validate([
            'invoice_number'       => ['nullable', 'string', 'max:100'],
            'invoice_date'         => ['nullable', 'date'],
            'supplier_tax_code'    => ['nullable', 'string', 'max:50'],
            'subtotal'             => ['required', 'numeric', 'min:0'],
            'tax_amount'           => ['required', 'numeric', 'min:0'],
            'total'                => ['required', 'numeric', 'min:0'],
            'due_date'             => ['nullable', 'date'],
            'notes'                => ['nullable', 'string'],
            'expense_account_code' => ['nullable', 'string', 'max:20'],
            'invoice_type'         => ['nullable', 'string', 'in:' . implode(',', array_column(PurchaseInvoiceType::cases(), 'value'))],
        ]);

        $purchaseInvoice->update($data);

        return redirect()->route('purchasing.purchase-invoices.show', $purchaseInvoice)
            ->with('success', 'Đã cập nhật hóa đơn.');
    }

    public function uploadAttachment(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        if ($purchaseInvoice->file_path) {
            Storage::disk('public')->delete($purchaseInvoice->file_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/purchase-invoices', 'public');
        $purchaseInvoice->update(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);

        return back()->with('success', 'Đã đính kèm file hóa đơn.');
    }

    public function deleteAttachment(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        if ($purchaseInvoice->file_path) {
            Storage::disk('public')->delete($purchaseInvoice->file_path);
            $purchaseInvoice->update(['file_path' => null, 'file_name' => null]);
        }

        return back()->with('success', 'Đã xóa file đính kèm.');
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $deletableStatuses = [PurchaseInvoiceStatus::Cancelled, PurchaseInvoiceStatus::Valid];
        if (!in_array($purchaseInvoice->status, $deletableStatuses)) {
            return back()->with('error', 'Chỉ có thể xóa hóa đơn đã hủy hoặc chưa thanh toán. Thu hồi thanh toán trước nếu hóa đơn đã thanh toán.');
        }

        if ($purchaseInvoice->amountPaid() > 0) {
            return back()->with('error', 'Hóa đơn còn thanh toán chưa thu hồi. Vui lòng thu hồi toàn bộ thanh toán trước khi xóa.');
        }

        DB::transaction(function () use ($purchaseInvoice) {
            // Đảo JE dịch vụ (nếu có) trước khi xóa
            $this->service->cleanupJeForDelete($purchaseInvoice);
            foreach ($purchaseInvoice->attachments as $att) {
                Storage::disk('public')->delete($att->file_path);
                $att->delete();
            }
            $purchaseInvoice->payments()->delete(); // xóa cả voided records
            $purchaseInvoice->delete();
        });

        return redirect()->route('purchasing.purchase-invoices.index')
            ->with('success', 'Đã xóa hóa đơn đầu vào.');
    }

    public function recallPayments(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $this->authorize('purchasing.approve');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $count = $this->service->recallPayments($purchaseInvoice, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã thu hồi {$count} khoản thanh toán của hóa đơn {$purchaseInvoice->code}. Hóa đơn chuyển về trạng thái Hợp lệ.");
    }

    public function transition(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);

        try {
            $newStatus = PurchaseInvoiceStatus::from($data['status']);
            $this->service->transition($purchaseInvoice, $newStatus);
        } catch (\ValueError $e) {
            return back()->with('error', 'Trạng thái không hợp lệ.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã cập nhật trạng thái hóa đơn.');
    }

    private function expenseAccountList(): array
    {
        return AccountCode::where('is_detail', true)
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('code', 'like', '154%')
                ->orWhere('code', 'like', '641%')
                ->orWhere('code', 'like', '642%')
                ->orWhere('code', 'like', '632%')
                ->orWhere('code', 'like', '811%')
            )
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => "{$a->code} — {$a->name}"])
            ->toArray();
    }

    private function invoiceTypeList(): array
    {
        return array_map(
            fn ($t) => ['value' => $t->value, 'label' => $t->label()],
            PurchaseInvoiceType::cases()
        );
    }

    private function detectInvoiceType(\Illuminate\Support\Collection $items): ?string
    {
        if ($items->isEmpty()) return null;

        $lineTypes = $items->pluck('line_type')->unique()->values();

        if ($lineTypes->count() !== 1) return null; // mixed → let user choose

        return PurchaseInvoiceType::fromLineType($lineTypes->first())?->value;
    }

    private function allowedTransitions(PurchaseInvoiceStatus $status): array
    {
        $map = [
            'pending'         => [
                ['value' => 'received',   'label' => 'Đã nhận HĐ'],
                ['value' => 'cancelled',  'label' => 'Hủy'],
            ],
            'received'        => [
                ['value' => 'reviewing',  'label' => 'Bắt đầu kiểm tra'],
                ['value' => 'cancelled',  'label' => 'Hủy'],
            ],
            'reviewing'       => [
                ['value' => 'valid',          'label' => 'Hợp lệ'],
                ['value' => 'need_supplement','label' => 'Cần bổ sung'],
                ['value' => 'cancelled',      'label' => 'Hủy'],
            ],
            'valid'           => [
                ['value' => 'cancelled', 'label' => 'Hủy'],
            ],
            'need_supplement' => [
                ['value' => 'reviewing', 'label' => 'Kiểm tra lại'],
                ['value' => 'cancelled', 'label' => 'Hủy'],
            ],
            'partial_paid'    => [
                ['value' => 'cancelled', 'label' => 'Hủy'],
            ],
        ];

        return $map[$status->value] ?? [];
    }
}
