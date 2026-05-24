<?php

namespace App\Http\Controllers\Support;

use App\Enums\WarrantyStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Warranty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WarrantyController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Warranty::with(['customer', 'product'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('code', 'ilike', "%{$q}%")
                   ->orWhere('product_name', 'ilike', "%{$q}%")
                   ->orWhere('serial_number', 'ilike', "%{$q}%");
            });
        }

        return Inertia::render('Support/Warranties/Index', [
            'warranties' => $query->paginate(20)->through(fn ($w) => [
                'id'           => $w->id,
                'code'         => $w->code,
                'customer'     => $w->customer->name,
                'product_name' => $w->product_name,
                'serial_number'=> $w->serial_number,
                'start_date'   => $w->start_date->format('d/m/Y'),
                'end_date'     => $w->end_date->format('d/m/Y'),
                'status'       => $w->status->value,
                'status_label' => $w->status->label(),
                'status_color' => $w->status->color(),
                'is_expiring_soon' => $w->status === WarrantyStatus::Active
                    && $w->end_date->diffInDays(now()) <= 30
                    && $w->end_date->isFuture(),
            ]),
            'filters'  => $request->only(['status', 'search']),
            'statuses' => collect(WarrantyStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Support/Warranties/Form', [
            'nextCode'  => Warranty::generateCode(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'orders'    => Order::orderByDesc('id')->get(['id', 'code']),
            'products'  => Product::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'max:20', 'unique:warranties,code'],
            'customer_id'      => ['required', 'exists:customers,id'],
            'order_id'         => ['nullable', 'exists:orders,id'],
            'product_id'       => ['required', 'exists:products,id'],
            'serial_number'    => ['nullable', 'string', 'max:100'],
            'start_date'       => ['required', 'date'],
            'duration_months'  => ['required', 'integer', 'min:1', 'max:120'],
            'terms'            => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        // Tìm serial nếu có serial_number
        $serialId = null;
        if (! empty($data['serial_number'])) {
            $serial = ProductSerial::where('serial_number', $data['serial_number'])
                ->where('product_id', $data['product_id'])
                ->first();
            $serialId = $serial?->id;
        }

        $endDate = \Carbon\Carbon::parse($data['start_date'])
            ->addMonths($data['duration_months']);

        $warranty = Warranty::create([
            ...$data,
            'product_serial_id' => $serialId,
            'product_name'      => $product->name,
            'end_date'          => $endDate,
            'status'            => WarrantyStatus::Active,
            'created_by'        => auth()->id(),
        ]);

        return redirect()->route('support.warranties.index')
            ->with('success', "Đã tạo bảo hành {$warranty->code}");
    }

    public function show(Warranty $warranty): Response
    {
        $warranty->load(['customer', 'order', 'product', 'creator']);

        return Inertia::render('Support/Warranties/Show', [
            'warranty' => [
                'id'             => $warranty->id,
                'code'           => $warranty->code,
                'customer'       => ['id' => $warranty->customer->id, 'name' => $warranty->customer->name],
                'order'          => $warranty->order ? ['id' => $warranty->order->id, 'code' => $warranty->order->code] : null,
                'product'        => ['id' => $warranty->product->id, 'name' => $warranty->product->name],
                'product_name'   => $warranty->product_name,
                'serial_number'  => $warranty->serial_number,
                'start_date'     => $warranty->start_date->format('d/m/Y'),
                'end_date'       => $warranty->end_date->format('d/m/Y'),
                'duration_months'=> $warranty->duration_months,
                'status'         => $warranty->status->value,
                'status_label'   => $warranty->status->label(),
                'status_color'   => $warranty->status->color(),
                'terms'          => $warranty->terms,
                'notes'          => $warranty->notes,
                'creator'        => $warranty->creator->name,
                'created_at'     => $warranty->created_at->format('d/m/Y'),
            ],
            'statuses' => collect(WarrantyStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function edit(Warranty $warranty): Response
    {
        return Inertia::render('Support/Warranties/Form', [
            'warranty'  => $warranty,
            'nextCode'  => $warranty->code,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'orders'    => Order::orderByDesc('id')->get(['id', 'code']),
            'products'  => Product::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Warranty $warranty): RedirectResponse
    {
        $data = $request->validate([
            'customer_id'     => ['required', 'exists:customers,id'],
            'order_id'        => ['nullable', 'exists:orders,id'],
            'product_id'      => ['required', 'exists:products,id'],
            'serial_number'   => ['nullable', 'string', 'max:100'],
            'start_date'      => ['required', 'date'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'terms'           => ['nullable', 'string'],
            'notes'           => ['nullable', 'string'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $endDate = \Carbon\Carbon::parse($data['start_date'])
            ->addMonths($data['duration_months']);

        $warranty->update([
            ...$data,
            'product_name' => $product->name,
            'end_date'     => $endDate,
        ]);

        return redirect()->route('support.warranties.show', $warranty)
            ->with('success', 'Đã cập nhật bảo hành.');
    }

    public function updateStatus(Request $request, Warranty $warranty): RedirectResponse
    {
        $request->validate(['status' => ['required', 'string']]);
        $warranty->update(['status' => WarrantyStatus::from($request->status)]);

        return back()->with('success', 'Đã cập nhật trạng thái bảo hành.');
    }

    public function destroy(Warranty $warranty): RedirectResponse
    {
        $this->authorize('tickets.close');
        $warranty->delete();

        return redirect()->route('support.warranties.index')
            ->with('success', 'Đã xóa bảo hành.');
    }
}
