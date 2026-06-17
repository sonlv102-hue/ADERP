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
            ->when($request->advance_type, fn ($q) => $q->where('advance_type', $request->advance_type))
            ->when($request->fiscal_year, fn ($q) => $q->where('fiscal_year', $request->fiscal_year))
            ->orderByDesc('opening_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Purchasing/SupplierAdvances/Index', [
            'advances'      => $query->through(fn ($adv) => [
                'id'               => $adv->id,
                'supplier'         => $adv->supplier->name,
                'advance_type'     => $adv->advance_type,
                'type_label'       => $adv->typeLabel(),
                'fiscal_year'      => $adv->fiscal_year,
                'opening_date'     => $adv->opening_date->format('d/m/Y'),
                'amount'           => (float) $adv->amount,
                'remaining_amount' => (float) $adv->remaining_amount,
                'reference_no'     => $adv->reference_no,
                'status'           => $adv->status,
                'status_label'     => $adv->statusLabel(),
            ]),
            'filters'        => $request->only(['search', 'supplier_id', 'status', 'fiscal_year', 'advance_type']),
            'suppliers'      => Supplier::orderBy('name')->get(['id', 'name', 'code']),
            'statusOptions'  => [
                ['value' => 'open',              'label' => 'Còn dư'],
                ['value' => 'partially_applied', 'label' => 'Đối trừ một phần'],
                ['value' => 'fully_applied',     'label' => 'Đã đối trừ hết'],
                ['value' => 'cancelled',         'label' => 'Đã hủy'],
            ],
            'typeOptions' => [
                ['value' => 'opening_balance', 'label' => 'Số dư đầu kỳ'],
                ['value' => 'prepayment',      'label' => 'Trả trước trong kỳ'],
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
        $advanceType = $request->input('advance_type', 'opening_balance');

        $rules = [
            'supplier_id'           => ['required', 'exists:suppliers,id'],
            'advance_type'          => ['required', 'in:opening_balance,prepayment'],
            'opening_date'          => ['required', 'date'],
            'amount'                => ['required', 'numeric', 'min:1'],
            'reference_no'          => ['nullable', 'string', 'max:100'],
            'notes'                 => ['nullable', 'string'],
        ];

        if ($advanceType === 'opening_balance') {
            $rules['fiscal_year']           = ['required', 'integer', 'min:2020', 'max:2099'];
            $rules['bank_transaction_ref']  = ['nullable', 'string', 'max:100'];
            $rules['original_payment_date'] = ['nullable', 'date'];
            $rules['original_payment_note'] = ['nullable', 'string'];
        } else {
            $rules['fiscal_year'] = ['nullable'];
        }

        $data = $request->validate($rules);

        $advance = $this->service->create($data);

        $msg = $advanceType === 'prepayment'
            ? 'Đã tạo khoản trả trước trong kỳ.'
            : 'Đã tạo khoản ứng trước đầu kỳ.';

        return redirect()->route('purchasing.supplier-advances.show', $advance)
            ->with('success', $msg);
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
        $rules = [
            'supplier_id'  => ['required', 'exists:suppliers,id'],
            'opening_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'min:1'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ];

        if ($supplierAdvance->advance_type === 'opening_balance') {
            $rules['fiscal_year']           = ['required', 'integer', 'min:2020', 'max:2099'];
            $rules['bank_transaction_ref']  = ['nullable', 'string', 'max:100'];
            $rules['original_payment_date'] = ['nullable', 'date'];
            $rules['original_payment_note'] = ['nullable', 'string'];
        }

        $data = $request->validate($rules);

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
