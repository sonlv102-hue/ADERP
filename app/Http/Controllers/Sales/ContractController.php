<?php

namespace App\Http\Controllers\Sales;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sales/Contracts/Index', [
            'contracts' => Contract::with(['customer', 'order', 'creator'])
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($c) => [
                    'id'           => $c->id,
                    'code'         => $c->code,
                    'title'        => $c->title,
                    'customer'     => $c->customer->name,
                    'order_code'   => $c->order?->code,
                    'value'        => $c->value,
                    'start_date'   => $c->start_date?->format('d/m/Y'),
                    'end_date'     => $c->end_date?->format('d/m/Y'),
                    'status'       => $c->status->value,
                    'status_label' => $c->status->label(),
                    'status_color' => $c->status->color(),
                    'creator'      => $c->creator->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Contracts/Form', [
            'nextCode'  => Contract::generateCode(),
            'customers' => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'orders'    => Order::with('items')->orderByDesc('id')->get(['id', 'code', 'customer_id'])->map(fn ($o) => [
                'id'          => $o->id,
                'code'        => $o->code,
                'customer_id' => $o->customer_id,
                'total'       => $o->total(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'       => ['required', 'string', 'unique:contracts,code'],
            'customer_id'=> ['required', 'exists:customers,id'],
            'order_id'   => ['nullable', 'exists:orders,id'],
            'title'      => ['required', 'string', 'max:255'],
            'value'      => ['required', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'      => ['nullable', 'string'],
        ]);

        $contract = Contract::create([
            ...$data,
            'created_by' => auth()->id(),
            'status'     => ContractStatus::Draft,
        ]);

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Đã tạo hợp đồng.');
    }

    public function show(Contract $contract): Response
    {
        $contract->load(['customer', 'order', 'creator']);

        return Inertia::render('Sales/Contracts/Show', [
            'contract' => [
                'id'           => $contract->id,
                'code'         => $contract->code,
                'title'        => $contract->title,
                'customer'     => ['id' => $contract->customer->id, 'name' => $contract->customer->name],
                'order'        => $contract->order ? ['id' => $contract->order->id, 'code' => $contract->order->code] : null,
                'value'        => $contract->value,
                'start_date'   => $contract->start_date?->format('d/m/Y'),
                'end_date'     => $contract->end_date?->format('d/m/Y'),
                'status'       => $contract->status->value,
                'status_label' => $contract->status->label(),
                'status_color' => $contract->status->color(),
                'file_name'    => $contract->file_name,
                'file_url'     => $contract->file_path ? Storage::disk('public')->url($contract->file_path) : null,
                'notes'        => $contract->notes,
                'creator'      => $contract->creator->name,
                'created_at'   => $contract->created_at->format('d/m/Y'),
            ],
        ]);
    }

    public function edit(Contract $contract): Response
    {
        abort_if($contract->status !== ContractStatus::Draft, 403, 'Chỉ có thể sửa hợp đồng ở trạng thái nháp.');

        return Inertia::render('Sales/Contracts/Form', [
            'contract' => [
                'id'          => $contract->id,
                'code'        => $contract->code,
                'customer_id' => $contract->customer_id,
                'order_id'    => $contract->order_id,
                'title'       => $contract->title,
                'value'       => $contract->value,
                'start_date'  => $contract->start_date?->format('Y-m-d'),
                'end_date'    => $contract->end_date?->format('Y-m-d'),
                'notes'       => $contract->notes,
            ],
            'customers' => Customer::orderBy('name')->get(['id', 'code', 'name']),
            'orders'    => Order::with('items')->orderByDesc('id')->get(['id', 'code', 'customer_id'])->map(fn ($o) => [
                'id'          => $o->id,
                'code'        => $o->code,
                'customer_id' => $o->customer_id,
                'total'       => $o->total(),
            ]),
        ]);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        abort_if($contract->status !== ContractStatus::Draft, 403);

        $data = $request->validate([
            'customer_id'=> ['required', 'exists:customers,id'],
            'order_id'   => ['nullable', 'exists:orders,id'],
            'title'      => ['required', 'string', 'max:255'],
            'value'      => ['required', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes'      => ['nullable', 'string'],
        ]);

        $contract->update($data);

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Đã cập nhật hợp đồng.');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        abort_if($contract->status !== ContractStatus::Draft, 403, 'Chỉ có thể xóa hợp đồng ở trạng thái nháp.');
        $contract->delete();

        return redirect()->route('sales.contracts.index')
            ->with('success', 'Đã xóa hợp đồng.');
    }

    public function activate(Contract $contract): RedirectResponse
    {
        if ($contract->status !== ContractStatus::Draft) {
            return back()->with('error', 'Chỉ có thể kích hoạt hợp đồng ở trạng thái nháp.');
        }
        $contract->update(['status' => ContractStatus::Active]);

        return back()->with('success', 'Hợp đồng đã được kích hoạt.');
    }

    public function complete(Contract $contract): RedirectResponse
    {
        if ($contract->status !== ContractStatus::Active) {
            return back()->with('error', 'Chỉ có thể hoàn thành hợp đồng đang hiệu lực.');
        }
        $contract->update(['status' => ContractStatus::Completed]);

        return back()->with('success', 'Đã hoàn thành hợp đồng.');
    }

    public function terminate(Contract $contract): RedirectResponse
    {
        if ($contract->status === ContractStatus::Completed || $contract->status === ContractStatus::Terminated) {
            return back()->with('error', 'Không thể chấm dứt hợp đồng này.');
        }
        $contract->update(['status' => ContractStatus::Terminated]);

        return back()->with('success', 'Đã chấm dứt hợp đồng.');
    }

    public function uploadAttachment(Request $request, Contract $contract): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        if ($contract->file_path) {
            Storage::disk('public')->delete($contract->file_path);
        }

        $file = $request->file('file');
        $path = $file->store('attachments/contracts', 'public');

        $contract->update(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Đã đính kèm file.');
    }

    public function deleteAttachment(Contract $contract): RedirectResponse
    {
        if ($contract->file_path) {
            Storage::disk('public')->delete($contract->file_path);
            $contract->update(['file_path' => null, 'file_name' => null]);
        }

        return redirect()->route('sales.contracts.show', $contract)
            ->with('success', 'Đã xóa file đính kèm.');
    }

    public function pdf(Contract $contract)
    {
        $contract->load(['customer', 'order', 'creator']);
        $pdf = Pdf::loadView('pdf.contract', compact('contract'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("HopDong-{$contract->code}.pdf");
    }
}
