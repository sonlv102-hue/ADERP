<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Fund;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseInvoiceService;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
                    'purchase_order' => $inv->purchaseOrder?->code,
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
            'nextCode'        => PurchaseInvoice::generateCode(),
            'projects'        => $this->projectList(),
            'creditAccounts'  => $this->creditAccountList(),
            'purchaseOrders'  => PurchaseOrder::with(['supplier', 'items'])
                ->whereIn('status', ['sent', 'received'])
                ->orderByDesc('id')
                ->get()
                ->map(fn ($po) => [
                    'id'          => $po->id,
                    'code'        => $po->code,
                    'supplier_id' => $po->supplier_id,
                    'supplier'    => $po->supplier->name,
                    // unit_price chưa gồm VAT — cộng thêm vat_rate của từng dòng PO để ra tax/total
                    'subtotal'    => $po->items->sum(fn ($i) => $i->quantity * $i->unit_price),
                    'tax_amount'  => $po->items->sum(fn ($i) =>
                        $i->quantity * $i->unit_price * (($i->vat_rate ?? 0) / 100)
                    ),
                    'default_invoice_type' => $this->detectInvoiceType($po->items),
                ]),
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
            'project_id'           => ['nullable', 'exists:projects,id'],
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
            'items'                             => ['nullable', 'array'],
            'items.*.description'               => ['nullable', 'string', 'max:255'],
            'items.*.account_code'              => ['required_with:items', 'string', 'max:20', 'exists:account_codes,code'],
            'items.*.credit_account_code'       => ['nullable', 'string', 'max:20', 'exists:account_codes,code'],
            'items.*.project_id'                => ['nullable', 'exists:projects,id'],
            'items.*.amount'                    => ['required_with:items', 'numeric', 'min:0'],
            'items.*.vat_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_amount'                => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->validateItemProjectLinks($data['items'] ?? []);

        $invoice = DB::transaction(function () use ($data) {
            $inv = PurchaseInvoice::create([
                ...\Arr::except($data, ['items']),
                'paid_amount' => 0,
                'status'      => PurchaseInvoiceStatus::Pending,
                'created_by'  => auth()->id(),
            ]);

            $this->syncItems($inv, $data['items'] ?? []);

            return $inv;
        });

        return redirect()->route('purchasing.purchase-invoices.show', $invoice)
            ->with('success', 'Đã tạo hóa đơn đầu vào.');
    }

    public function show(PurchaseInvoice $purchaseInvoice): Response
    {
        $purchaseInvoice->load([
            'supplier', 'purchaseOrder.items.product', 'purchaseOrder.project',
            'project', 'items.project', 'creator',
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
                // Dự án gắn trực tiếp lên hóa đơn (ưu tiên) hoặc qua PO
                'project'           => ($purchaseInvoice->project ?? $purchaseInvoice->purchaseOrder?->project)
                    ? (function ($pj) { return ['id' => $pj->id, 'code' => $pj->code, 'name' => $pj->name]; })($purchaseInvoice->project ?? $purchaseInvoice->purchaseOrder->project)
                    : null,
                'items'             => $purchaseInvoice->items->map(fn ($item) => [
                    'id'                  => $item->id,
                    'description'         => $item->description,
                    'account_code'        => $item->account_code,
                    'credit_account_code' => $item->credit_account_code,
                    'project_id'          => $item->project_id,
                    'project'             => $item->project ? ['id' => $item->project->id, 'code' => $item->project->code, 'name' => $item->project->name] : null,
                    'amount'              => (float) $item->amount,
                    'vat_rate'            => (float) $item->vat_rate,
                    'tax_amount'          => (float) $item->tax_amount,
                ]),
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
                    'advance_type'     => $adv->advance_type,
                    'type_label'       => $adv->typeLabel(),
                    'account_code'     => $adv->account_code,
                    'status'           => $adv->status,
                    'status_label'     => $adv->statusLabel(),
                ]),
            'funds' => Fund::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
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
        $purchaseInvoice->load(['supplier', 'items.project']);

        return Inertia::render('Purchasing/PurchaseInvoices/Form', [
            'invoice'             => array_merge($purchaseInvoice->toArray(), [
                'items' => $purchaseInvoice->items->map(fn ($item) => [
                    'id'                  => $item->id,
                    'description'         => $item->description,
                    'account_code'        => $item->account_code,
                    'credit_account_code' => $item->credit_account_code,
                    'project_id'          => $item->project_id,
                    'project_name'        => $item->project ? "{$item->project->code} — {$item->project->name}" : null,
                    'amount'              => (float) $item->amount,
                    'vat_rate'            => (float) $item->vat_rate,
                    'tax_amount'          => (float) $item->tax_amount,
                    'sort_order'          => $item->sort_order,
                ])->toArray(),
            ]),
            'initialSupplierName' => $purchaseInvoice->supplier?->name ?? '',
            'initialSupplierCode' => $purchaseInvoice->supplier?->code ?? '',
            'projects'        => $this->projectList(),
            'creditAccounts'  => $this->creditAccountList(),
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
            'expenseAccounts' => $this->expenseAccountList(),
            'invoiceTypes'    => $this->invoiceTypeList(),
        ]);
    }

    public function update(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validate([
            'project_id'           => ['nullable', 'exists:projects,id'],
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
            'items'                             => ['nullable', 'array'],
            'items.*.description'               => ['nullable', 'string', 'max:255'],
            'items.*.account_code'              => ['required_with:items', 'string', 'max:20', 'exists:account_codes,code'],
            'items.*.credit_account_code'       => ['nullable', 'string', 'max:20', 'exists:account_codes,code'],
            'items.*.project_id'                => ['nullable', 'exists:projects,id'],
            'items.*.amount'                    => ['required_with:items', 'numeric', 'min:0'],
            'items.*.vat_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_amount'                => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->validateItemProjectLinks($data['items'] ?? []);

        DB::transaction(function () use ($purchaseInvoice, $data) {
            $purchaseInvoice->update(Arr::except($data, ['items']));
            $this->syncItems($purchaseInvoice, $data['items'] ?? []);
        });

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

    /**
     * Validate: dòng chi phí TK bắt đầu bằng 154 phải có project_id.
     */
    private function validateItemProjectLinks(array $items): void
    {
        foreach ($items as $index => $item) {
            $code = $item['account_code'] ?? '';
            if (str_starts_with((string) $code, '154') && empty($item['project_id'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.project_id" => 'Dòng chi phí hạch toán vào TK 154 phải gắn với dự án.',
                ]);
            }
        }
    }

    /**
     * Đồng bộ items: xóa cũ, tạo mới.
     */
    private function syncItems(PurchaseInvoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $index => $item) {
            $invoice->items()->create([
                'description'         => $item['description'] ?? null,
                'account_code'        => $item['account_code'],
                'credit_account_code' => $item['credit_account_code'] ?? null,
                'project_id'          => $item['project_id'] ?? null,
                'amount'              => $item['amount'] ?? 0,
                'vat_rate'            => $item['vat_rate'] ?? 0,
                'tax_amount'          => $item['tax_amount'] ?? 0,
                'sort_order'          => $index,
            ]);
        }
    }

    private function projectList(): array
    {
        return Project::where('status', ProjectStatus::InProgress)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'label' => "{$p->code} — {$p->name}"])
            ->toArray();
    }

    private function creditAccountList(): array
    {
        // TK Có cho dòng chi phí: 3311/3312/3318 (công nợ NCC) + 1111/1121 (trả tiền mặt/ck)
        $codes = ['3311', '3312', '3318', '1111', '1121'];

        return AccountCode::whereIn('code', $codes)
            ->where('is_detail', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => "{$a->code} — {$a->name}"])
            ->toArray();
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

    public function exportExcel(\Illuminate\Http\Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\PurchaseInvoicesExport($request->all()),
            'hoa-don-dau-vao_' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}
