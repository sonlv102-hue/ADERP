<?php

namespace App\Http\Controllers\Sales;

use App\Enums\QuotationStatus;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Service;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    public function __construct(private QuotationService $quotationService) {}

    public function index(Request $request): Response
    {
        $q      = $request->input('q');
        $status = $request->input('status');

        return Inertia::render('Sales/Quotations/Index', [
            'quotations' => Quotation::with(['customer', 'creator'])
                ->when($q, fn ($query) => $query->where(function ($sq) use ($q) {
                    $sq->where('code', 'ilike', "%{$q}%")
                       ->orWhere('notes', 'ilike', "%{$q}%")
                       ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%")
                                                              ->orWhere('code', 'ilike', "%{$q}%"))
                       ->orWhereHas('creator', fn ($u) => $u->where('name', 'ilike', "%{$q}%"));
                }))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($qt) => [
                    'id'            => $qt->id,
                    'code'          => $qt->code,
                    'customer'      => $qt->customer->name,
                    'valid_until'   => $qt->valid_until?->format('d/m/Y'),
                    'status'        => $qt->status->value,
                    'status_label'  => $qt->status->label(),
                    'status_color'  => $qt->status->color(),
                    'creator'       => $qt->creator->name,
                    'items_count'   => $qt->items()->count(),
                    'total'         => $qt->total(),
                    'created_at'    => $qt->created_at->format('d/m/Y'),
                ]),
            'filters'  => ['q' => $q, 'status' => $status],
            'statuses' => collect(QuotationStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Quotations/Form', [
            'nextCode'   => Quotation::generateCode(),
            'priceLists' => PriceList::select('id', 'code', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'unique:quotations,code'],
            'customer_id'    => ['required', 'exists:customers,id'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'valid_until'    => ['nullable', 'date'],
            'discount_type'  => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.item_type'       => ['required', 'in:product,service'],
            'items.*.product_id'      => ['nullable', 'exists:products,id'],
            'items.*.service_id'      => ['nullable', 'exists:services,id'],
            'items.*.name'            => ['required', 'string'],
            'items.*.unit'            => ['nullable', 'string'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent'=> ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $quotation = Quotation::create([
            'code'           => $data['code'],
            'customer_id'    => $data['customer_id'],
            'assigned_to'    => $data['assigned_to'] ?? null,
            'valid_until'    => $data['valid_until'] ?? null,
            'discount_type'  => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'notes'          => $data['notes'] ?? null,
            'created_by'     => auth()->id(),
            'status'         => QuotationStatus::Draft,
        ]);

        foreach ($data['items'] as $item) {
            $quotation->items()->create($item);
        }

        return redirect()->route('sales.quotations.show', $quotation)
            ->with('success', 'Đã tạo báo giá.');
    }

    public function show(Quotation $quotation): Response
    {
        $quotation->load(['customer', 'assignedTo', 'creator', 'items.product', 'items.service', 'orders', 'attachments.creator']);

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => [
                'id'              => $quotation->id,
                'code'            => $quotation->code,
                'customer'        => ['id' => $quotation->customer->id, 'name' => $quotation->customer->name, 'code' => $quotation->customer->code],
                'assigned_to'     => $quotation->assignedTo?->name,
                'valid_until'     => $quotation->valid_until?->format('d/m/Y'),
                'status'          => $quotation->status->value,
                'status_label'    => $quotation->status->label(),
                'status_color'    => $quotation->status->color(),
                'discount_type'   => $quotation->discount_type,
                'discount_value'  => $quotation->discount_value,
                'discount_percent' => $quotation->discountPercent(),
                'notes'           => $quotation->notes,
                'creator'         => $quotation->creator->name,
                'created_at'      => $quotation->created_at->format('d/m/Y'),
                'subtotal'        => $quotation->subtotal(),
                'discount_amount' => $quotation->discountAmount(),
                'total'           => $quotation->total(),
                'items'           => $quotation->items->map(fn ($item) => [
                    'id'               => $item->id,
                    'item_type'        => $item->item_type,
                    'name'             => $item->name,
                    'unit'             => $item->unit,
                    'quantity'         => $item->quantity,
                    'unit_price'       => $item->unit_price,
                    'vat_rate'         => $item->vat_rate !== null ? (float) $item->vat_rate : null,
                    'vat_amount'       => $item->vat_rate !== null ? (int) round($item->lineTotal() * $item->vat_rate / 100) : 0,
                    'discount_percent' => $item->discount_percent,
                    'discount_amount'  => (int) $item->discount_amount,
                    'line_total'       => $item->lineTotal(),
                ]),
                'orders' => $quotation->orders->map(fn ($o) => [
                    'id'   => $o->id,
                    'code' => $o->code,
                ]),
                'attachments' => $quotation->attachments->map(fn ($a) => [
                    'id'        => $a->id,
                    'file_name' => $a->file_name,
                    'file_url'  => Storage::disk('public')->url($a->file_path),
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                    'created_by'=> $a->creator->name,
                ]),
                'can_edit' => $quotation->status === QuotationStatus::Draft
                    || (auth()->user()->can('admin.users') && $quotation->status !== QuotationStatus::Cancelled),
            ],
        ]);
    }

    public function edit(Quotation $quotation): Response
    {
        $isAdmin = auth()->user()->can('admin.users');
        abort_if(
            $quotation->status !== QuotationStatus::Draft
                && !($isAdmin && $quotation->status !== QuotationStatus::Cancelled),
            403,
            'Chỉ có thể sửa báo giá ở trạng thái nháp.'
        );

        $quotation->load(['items', 'customer']);

        return Inertia::render('Sales/Quotations/Form', [
            'quotation' => [
                'id'                  => $quotation->id,
                'code'                => $quotation->code,
                'customer_id'         => $quotation->customer_id,
                'customer_name'       => $quotation->customer?->name ?? '',
                'customer_code'       => $quotation->customer?->code ?? '',
                'assigned_to'         => $quotation->assigned_to,
                'valid_until'         => $quotation->valid_until?->format('Y-m-d'),
                'discount_type'       => $quotation->discount_type,
                'discount_value'      => (float) $quotation->discount_value,
                'notes'               => $quotation->notes,
                'items'               => $quotation->items->map(fn ($item) => [
                    'id'               => $item->id,
                    'item_type'        => $item->item_type,
                    'product_id'       => $item->product_id,
                    'service_id'       => $item->service_id,
                    'name'             => $item->name,
                    'unit'             => $item->unit,
                    'quantity'         => (float) $item->quantity,
                    'unit_price'       => (float) $item->unit_price,
                    'discount_percent' => (float) $item->discount_percent,
                    'discount_amount'  => (int) $item->discount_amount,
                ]),
            ],
            'priceLists' => PriceList::select('id', 'code', 'name')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        $isAdmin = auth()->user()->can('admin.users');
        abort_if(
            $quotation->status !== QuotationStatus::Draft
                && !($isAdmin && $quotation->status !== QuotationStatus::Cancelled),
            403
        );

        $data = $request->validate([
            'customer_id'    => ['required', 'exists:customers,id'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'valid_until'    => ['nullable', 'date'],
            'discount_type'  => ['required', 'in:percent,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
            'items'          => ['required', 'array', 'min:1'],
            'items.*.item_type'       => ['required', 'in:product,service'],
            'items.*.product_id'      => ['nullable', 'exists:products,id'],
            'items.*.service_id'      => ['nullable', 'exists:services,id'],
            'items.*.name'            => ['required', 'string'],
            'items.*.unit'            => ['nullable', 'string'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent'=> ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $quotation->update([
            'customer_id'    => $data['customer_id'],
            'assigned_to'    => $data['assigned_to'] ?? null,
            'valid_until'    => $data['valid_until'] ?? null,
            'discount_type'  => $data['discount_type'],
            'discount_value' => $data['discount_value'],
            'notes'          => $data['notes'] ?? null,
        ]);

        $quotation->items()->delete();
        foreach ($data['items'] as $item) {
            $quotation->items()->create($item);
        }

        return redirect()->route('sales.quotations.show', $quotation)
            ->with('success', 'Đã cập nhật báo giá.');
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        $this->authorize('admin.users');

        abort_if(
            !in_array($quotation->status, [
                QuotationStatus::Draft,
                QuotationStatus::Cancelled,
                QuotationStatus::Rejected,
                QuotationStatus::Expired,
            ]),
            403,
            'Chỉ có thể xóa báo giá ở trạng thái nháp, đã hủy, từ chối hoặc hết hạn.'
        );
        $quotation->delete();

        return redirect()->route('sales.quotations.index')
            ->with('success', 'Đã xóa báo giá.');
    }

    public function markSent(Quotation $quotation): RedirectResponse
    {
        try {
            $this->quotationService->markSent($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã đánh dấu báo giá là "Đã gửi".');
    }

    public function approve(Quotation $quotation): RedirectResponse
    {
        try {
            $this->quotationService->approve($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã duyệt báo giá.');
    }

    public function reject(Quotation $quotation): RedirectResponse
    {
        try {
            $this->quotationService->reject($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã từ chối báo giá.');
    }

    public function cancel(Quotation $quotation): RedirectResponse
    {
        $this->authorize('admin.users');
        try {
            $this->quotationService->cancel($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy báo giá.');
    }

    public function recall(Quotation $quotation): RedirectResponse
    {
        $this->authorize('admin.users');
        try {
            $this->quotationService->recall($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã thu hồi báo giá về trạng thái Nháp.');
    }

    public function unapprove(Quotation $quotation): RedirectResponse
    {
        $this->authorize('admin.users');
        try {
            $this->quotationService->unapprove($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã hủy duyệt báo giá.');
    }

    public function convertToOrder(Quotation $quotation): RedirectResponse
    {
        try {
            $order = $this->quotationService->convertToOrder($quotation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.orders.show', $order)
            ->with('success', 'Đã tạo đơn hàng từ báo giá.');
    }

    public function uploadAttachment(Request $request, Quotation $quotation): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        if ($quotation->file_path) {
            Storage::disk('public')->delete($quotation->file_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/quotations', 'public');

        $quotation->update(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);

        return redirect()->route('sales.quotations.show', $quotation)
            ->with('success', 'Đã đính kèm file.');
    }

    public function deleteAttachment(Quotation $quotation): RedirectResponse
    {
        if ($quotation->file_path) {
            Storage::disk('public')->delete($quotation->file_path);
            $quotation->update(['file_path' => null, 'file_name' => null]);
        }

        return redirect()->route('sales.quotations.show', $quotation)
            ->with('success', 'Đã xóa file đính kèm.');
    }

    public function pdf(Quotation $quotation)
    {
        $quotation->load(['customer', 'assignedTo', 'creator', 'items']);
        $pdf = Pdf::loadView('pdf.quotation', compact('quotation'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("BaoGia-{$quotation->code}.pdf");
    }

    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\QuotationsExport($request->all()),
            'bao-gia_' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}
