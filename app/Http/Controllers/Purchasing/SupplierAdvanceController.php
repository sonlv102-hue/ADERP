<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierOpeningAdvance;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierAdvanceController extends Controller
{
    public function __construct(private SupplierAdvanceService $service) {}

    public function index(Request $request)
    {
        $query = SupplierOpeningAdvance::with('supplier')
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('reference_no', 'ilike', "%{$request->search}%")
                       ->orWhereHas('supplier', fn ($q3) => $q3->where('name', 'ilike', "%{$request->search}%"))
                )
            )
            ->when($request->supplier_id, fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->fiscal_year, fn ($q) => $q->where('fiscal_year', $request->fiscal_year))
            ->orderByDesc('opening_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Purchasing/SupplierAdvances/Index', [
            'advances'      => $query,
            'filters'       => $request->only(['search', 'supplier_id', 'status', 'fiscal_year']),
            'suppliers'     => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'statusOptions' => [
                ['value' => 'open',              'label' => 'Còn dư'],
                ['value' => 'partially_applied', 'label' => 'Đối trừ một phần'],
                ['value' => 'fully_applied',     'label' => 'Đã đối trừ hết'],
                ['value' => 'cancelled',         'label' => 'Đã hủy'],
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Purchasing/SupplierAdvances/Form', [
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'           => ['required', 'exists:suppliers,id'],
            'fiscal_year'           => ['required', 'integer', 'min:2020', 'max:2099'],
            'opening_date'          => ['required', 'date'],
            'amount'                => ['required', 'numeric', 'min:1'],
            'reference_no'          => ['nullable', 'string', 'max:100'],
            'bank_transaction_ref'  => ['nullable', 'string', 'max:100'],
            'original_payment_date' => ['nullable', 'date'],
            'original_payment_note' => ['nullable', 'string'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $advance = $this->service->create($data);

        return redirect()->route('purchasing.supplier-advances.show', $advance)
            ->with('success', 'Đã tạo khoản ứng trước đầu kỳ.');
    }

    public function show(SupplierOpeningAdvance $supplierAdvance)
    {
        $supplierAdvance->load([
            'supplier',
            'creator',
            'allocations' => fn ($q) =>
                $q->with(['invoice', 'creator', 'reversedBy'])
                  ->orderByDesc('allocation_date')
                  ->orderByDesc('id'),
        ]);

        return Inertia::render('Purchasing/SupplierAdvances/Show', [
            'advance' => $supplierAdvance,
        ]);
    }

    public function edit(SupplierOpeningAdvance $supplierAdvance)
    {
        return Inertia::render('Purchasing/SupplierAdvances/Form', [
            'advance'   => $supplierAdvance->load('supplier'),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function update(Request $request, SupplierOpeningAdvance $supplierAdvance)
    {
        $data = $request->validate([
            'supplier_id'           => ['required', 'exists:suppliers,id'],
            'fiscal_year'           => ['required', 'integer', 'min:2020', 'max:2099'],
            'opening_date'          => ['required', 'date'],
            'amount'                => ['required', 'numeric', 'min:1'],
            'reference_no'          => ['nullable', 'string', 'max:100'],
            'bank_transaction_ref'  => ['nullable', 'string', 'max:100'],
            'original_payment_date' => ['nullable', 'date'],
            'original_payment_note' => ['nullable', 'string'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $this->service->update($supplierAdvance, $data);

        return redirect()->route('purchasing.supplier-advances.show', $supplierAdvance)
            ->with('success', 'Đã cập nhật khoản ứng trước.');
    }

    public function cancel(Request $request, SupplierOpeningAdvance $supplierAdvance)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->service->cancel($supplierAdvance, $data['reason']);
        return back()->with('success', 'Đã hủy khoản ứng trước.');
    }
}
