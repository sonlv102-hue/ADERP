<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseContractStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseContract;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseContractController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Purchasing/PurchaseContracts/Index', [
            'contracts' => PurchaseContract::with(['supplier', 'purchaseOrder', 'creator'])
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($c) => [
                    'id'            => $c->id,
                    'code'          => $c->code,
                    'title'         => $c->title,
                    'supplier'      => $c->supplier->name,
                    'order_code'    => $c->purchaseOrder?->code,
                    'value'         => $c->value,
                    'start_date'    => $c->start_date?->format('d/m/Y'),
                    'end_date'      => $c->end_date?->format('d/m/Y'),
                    'status'        => $c->status->value,
                    'status_label'  => $c->status->label(),
                    'status_color'  => $c->status->color(),
                    'creator'       => $c->creator->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchasing/PurchaseContracts/Form', [
            'nextCode'  => PurchaseContract::generateCode(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'orders'    => PurchaseOrder::with('supplier')
                ->orderByDesc('id')
                ->get(['id', 'code', 'supplier_id'])
                ->map(fn ($o) => [
                    'id'          => $o->id,
                    'code'        => $o->code,
                    'supplier_id' => $o->supplier_id,
                    'total'       => $o->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_price')),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'              => ['required', 'string', 'unique:purchase_contracts,code'],
            'supplier_id'       => ['required', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id', 'unique:purchase_contracts,purchase_order_id'],
            'title'             => ['required', 'string', 'max:255'],
            'value'             => ['required', 'numeric', 'min:0'],
            'start_date'        => ['nullable', 'date'],
            'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $contract = PurchaseContract::create([
            ...$data,
            'created_by' => auth()->id(),
            'status'     => PurchaseContractStatus::Draft,
        ]);

        return redirect()->route('purchasing.purchase-contracts.show', $contract)
            ->with('success', 'Đã tạo hợp đồng mua.');
    }

    public function show(PurchaseContract $purchaseContract): Response
    {
        $purchaseContract->load(['supplier', 'purchaseOrder', 'creator', 'paymentSchedules', 'attachments.creator']);

        $contractValue = (float) $purchaseContract->value;

        $schedules = $purchaseContract->paymentSchedules->map(fn ($s) => [
            'id'          => $s->id,
            'name'        => $s->name,
            'percentage'  => (float) $s->percentage,
            'amount'      => round($contractValue * (float) $s->percentage / 100, 0),
            'due_date'    => $s->due_date?->format('Y-m-d'),
            'due_date_label' => $s->due_date?->format('d/m/Y'),
            'status'      => $s->effective_status->value,
            'status_label'=> $s->effective_status->label(),
            'status_color'=> $s->effective_status->color(),
            'paid_date'          => $s->paid_date?->format('d/m/Y'),
            'payment_method'     => $s->payment_method,
            'payment_reference'  => $s->payment_reference,
            'notes'              => $s->notes,
        ]);

        $totalPercent = $schedules->sum('percentage');
        $paidPercent  = $schedules->where('status', 'paid')->sum('percentage');
        $paidAmount   = $schedules->where('status', 'paid')->sum('amount');

        return Inertia::render('Purchasing/PurchaseContracts/Show', [
            'contract' => [
                'id'           => $purchaseContract->id,
                'code'         => $purchaseContract->code,
                'title'        => $purchaseContract->title,
                'supplier'     => ['id' => $purchaseContract->supplier->id, 'name' => $purchaseContract->supplier->name],
                'order'        => $purchaseContract->purchaseOrder
                    ? ['id' => $purchaseContract->purchaseOrder->id, 'code' => $purchaseContract->purchaseOrder->code]
                    : null,
                'value'        => (float) $purchaseContract->value,
                'start_date'   => $purchaseContract->start_date?->format('d/m/Y'),
                'end_date'     => $purchaseContract->end_date?->format('d/m/Y'),
                'status'       => $purchaseContract->status->value,
                'status_label' => $purchaseContract->status->label(),
                'status_color' => $purchaseContract->status->color(),
                'attachments' => $purchaseContract->attachments->map(fn ($a) => [
                    'id'        => $a->id,
                    'file_name' => $a->file_name,
                    'file_url'  => Storage::disk('public')->url($a->file_path),
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                    'created_by'=> $a->creator->name,
                ]),
                'notes'        => $purchaseContract->notes,
                'creator'      => $purchaseContract->creator->name,
                'created_at'   => $purchaseContract->created_at->format('d/m/Y'),
                'schedules'       => $schedules,
                'total_percent'   => round($totalPercent, 2),
                'paid_percent'    => round($paidPercent, 2),
                'paid_amount'     => $paidAmount,
                'remaining_amount'=> (float) $purchaseContract->value - $paidAmount,
            ],
        ]);
    }

    public function edit(PurchaseContract $purchaseContract): Response
    {
        abort_if($purchaseContract->status !== PurchaseContractStatus::Draft, 403, 'Chỉ sửa được hợp đồng ở trạng thái nháp.');

        return Inertia::render('Purchasing/PurchaseContracts/Form', [
            'contract'  => [
                'id'                => $purchaseContract->id,
                'code'              => $purchaseContract->code,
                'supplier_id'       => $purchaseContract->supplier_id,
                'purchase_order_id' => $purchaseContract->purchase_order_id,
                'title'             => $purchaseContract->title,
                'value'             => $purchaseContract->value,
                'start_date'        => $purchaseContract->start_date?->format('Y-m-d'),
                'end_date'          => $purchaseContract->end_date?->format('Y-m-d'),
                'notes'             => $purchaseContract->notes,
            ],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'code', 'name']),
            'orders'    => PurchaseOrder::orderByDesc('id')->get(['id', 'code', 'supplier_id'])
                ->map(fn ($o) => ['id' => $o->id, 'code' => $o->code, 'supplier_id' => $o->supplier_id, 'total' => 0]),
        ]);
    }

    public function update(Request $request, PurchaseContract $purchaseContract): RedirectResponse
    {
        abort_if($purchaseContract->status !== PurchaseContractStatus::Draft, 403);

        $data = $request->validate([
            'supplier_id'       => ['required', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id', "unique:purchase_contracts,purchase_order_id,{$purchaseContract->id}"],
            'title'             => ['required', 'string', 'max:255'],
            'value'             => ['required', 'numeric', 'min:0'],
            'start_date'        => ['nullable', 'date'],
            'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $purchaseContract->update($data);

        return redirect()->route('purchasing.purchase-contracts.show', $purchaseContract)
            ->with('success', 'Đã cập nhật hợp đồng mua.');
    }

    public function destroy(PurchaseContract $purchaseContract): RedirectResponse
    {
        abort_if($purchaseContract->status !== PurchaseContractStatus::Draft, 403, 'Chỉ xóa được hợp đồng ở trạng thái nháp.');
        $purchaseContract->delete();

        return redirect()->route('purchasing.purchase-contracts.index')
            ->with('success', 'Đã xóa hợp đồng mua.');
    }

    public function activate(PurchaseContract $purchaseContract): RedirectResponse
    {
        abort_if($purchaseContract->status !== PurchaseContractStatus::Draft, 403);
        $purchaseContract->update(['status' => PurchaseContractStatus::Active]);

        return back()->with('success', 'Hợp đồng đã được kích hoạt.');
    }

    public function complete(PurchaseContract $purchaseContract): RedirectResponse
    {
        abort_if($purchaseContract->status !== PurchaseContractStatus::Active, 403);
        $purchaseContract->update(['status' => PurchaseContractStatus::Completed]);

        return back()->with('success', 'Đã hoàn thành hợp đồng mua.');
    }

    public function terminate(PurchaseContract $purchaseContract): RedirectResponse
    {
        abort_if(
            in_array($purchaseContract->status, [PurchaseContractStatus::Completed, PurchaseContractStatus::Terminated]),
            403
        );
        $purchaseContract->update(['status' => PurchaseContractStatus::Terminated]);

        return back()->with('success', 'Đã chấm dứt hợp đồng mua.');
    }

    public function uploadAttachment(Request $request, PurchaseContract $purchaseContract): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        if ($purchaseContract->file_path) {
            Storage::disk('public')->delete($purchaseContract->file_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/purchase-contracts', 'public');
        $purchaseContract->update(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);

        return back()->with('success', 'Đã đính kèm file.');
    }

    public function deleteAttachment(PurchaseContract $purchaseContract): RedirectResponse
    {
        if ($purchaseContract->file_path) {
            Storage::disk('public')->delete($purchaseContract->file_path);
            $purchaseContract->update(['file_path' => null, 'file_name' => null]);
        }

        return back()->with('success', 'Đã xóa file đính kèm.');
    }
}
