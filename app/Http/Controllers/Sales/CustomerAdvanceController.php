<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Http\Controllers\Controller;
use App\Models\CashVoucher;
use App\Models\Customer;
use App\Models\CustomerOpeningAdvance;
use App\Services\CashVoucherService;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerAdvanceController extends Controller
{
    public function __construct(
        private CustomerAdvanceService $service,
        private CashVoucherService $cashVoucherService,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('accounting.view');

        $query = CustomerOpeningAdvance::with('customer')
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q2) =>
                    $q2->where('reference_no', 'ilike', "%{$request->search}%")
                       ->orWhereHas('customer', fn ($q3) => $q3->where('name', 'ilike', "%{$request->search}%"))
                )
            )
            ->when($request->customer_id, fn ($q) => $q->where('customer_id', $request->customer_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->advance_type, fn ($q) => $q->where('advance_type', $request->advance_type))
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Sales/CustomerAdvances/Index', [
            'advances'      => $query->through(fn ($adv) => [
                'id'               => $adv->id,
                'customer'         => $adv->customer->name,
                'customer_code'    => $adv->customer->code,
                'advance_type'     => $adv->advance_type,
                'type_label'       => $adv->typeLabel(),
                'advance_date'     => $adv->advance_date->format('d/m/Y'),
                'amount'           => (float) $adv->amount,
                'remaining_amount' => (float) $adv->remaining_amount,
                'reference_no'     => $adv->reference_no,
                'status'           => $adv->status,
                'status_label'     => $adv->statusLabel(),
            ]),
            'filters'       => $request->only(['search', 'customer_id', 'status', 'advance_type']),
            'customers'     => Customer::orderBy('name')->get(['id', 'name', 'code']),
            'statusOptions' => [
                ['value' => 'open',              'label' => 'Còn dư'],
                ['value' => 'partially_applied', 'label' => 'Đối trừ một phần'],
                ['value' => 'fully_applied',     'label' => 'Đã đối trừ hết'],
                ['value' => 'cancelled',         'label' => 'Đã hủy'],
            ],
            'typeOptions' => [
                ['value' => 'opening_balance', 'label' => 'Số dư đầu kỳ'],
                ['value' => 'advance_receipt', 'label' => 'Nhận ứng trước trong kỳ'],
            ],
            'summary' => [
                'open'            => CustomerOpeningAdvance::whereIn('status', ['open', 'partially_applied'])->count(),
                'total_remaining' => (float) CustomerOpeningAdvance::whereIn('status', ['open', 'partially_applied'])->sum('remaining_amount'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('accounting.manage');

        return Inertia::render('Sales/CustomerAdvances/Form', [
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'code']),
            'funds'     => \App\Models\Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('accounting.manage');

        $advanceType = $request->input('advance_type', 'opening_balance');

        $rules = [
            'customer_id'  => ['required', 'exists:customers,id'],
            'advance_type' => ['required', 'in:opening_balance,advance_receipt'],
            'advance_date' => ['required', 'date'],
            'amount'       => ['required', 'numeric', 'min:1'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ];

        if ($advanceType === 'opening_balance') {
            $rules['fiscal_year'] = ['required', 'integer', 'min:2020', 'max:2099'];
        } else {
            $rules['fiscal_year']      = ['nullable'];
            $rules['fund_id']          = ['required', Rule::exists('funds', 'id')->where('is_active', true)];
            $rules['payment_method']   = ['required', 'in:cash,bank_transfer'];
        }

        $data = $request->validate($rules);

        try {
            $advance = DB::transaction(function () use ($data, $advanceType) {
                $advance = $this->service->create($data);

                if ($advanceType === 'advance_receipt') {
                    $fund = \App\Models\Fund::findOrFail($data['fund_id']);
                    $voucher = CashVoucher::create([
                        'code'           => CashVoucher::generateCode(CashVoucherType::Receipt),
                        'type'           => CashVoucherType::Receipt,
                        'status'         => CashVoucherStatus::Draft,
                        'fund_id'        => $fund->id,
                        'customer_id'    => $advance->customer_id,
                        'partner_type'   => 'customer',
                        'amount'         => $advance->amount,
                        'voucher_date'   => $advance->advance_date,
                        'description'    => 'Nhận ứng trước KH' . ($advance->reference_no ? " {$advance->reference_no}" : ''),
                        'business_type'  => CashVoucherBusinessType::CustomerAdvance->value,
                        'reference_type' => CustomerOpeningAdvance::class,
                        'reference_id'   => $advance->id,
                        'created_by'     => auth()->id(),
                    ]);
                    $this->cashVoucherService->confirm($voucher);
                }

                return $advance;
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        $msg = $advanceType === 'advance_receipt'
            ? 'Đã nhận ứng trước khách hàng và ghi sổ phiếu thu.'
            : 'Đã tạo số dư ứng trước đầu kỳ.';

        return redirect()->route('sales.customer-advances.show', $advance)
            ->with('success', $msg);
    }

    public function show(CustomerOpeningAdvance $customerAdvance)
    {
        $this->authorize('accounting.view');

        $customerAdvance->load([
            'customer',
            'creator',
            'allocations' => fn ($q) =>
                $q->with(['invoice', 'creator', 'reversedBy'])
                  ->orderByDesc('allocation_date')
                  ->orderByDesc('id'),
        ]);

        return Inertia::render('Sales/CustomerAdvances/Show', [
            'advance' => array_merge($customerAdvance->toArray(), [
                'type_label'   => $customerAdvance->typeLabel(),
                'status_label' => $customerAdvance->statusLabel(),
                'allocations'  => $customerAdvance->allocations->map(fn ($a) => [
                    'id'              => $a->id,
                    'allocation_date' => $a->allocation_date,
                    'invoice_id'      => $a->invoice_id,
                    'allocated_amount' => (float) $a->allocated_amount,
                    'reason'          => $a->reason,
                    'status'          => $a->status,
                    'invoice'         => $a->invoice ? ['code' => $a->invoice->code] : null,
                    'creator'         => $a->creator ? ['name' => $a->creator->name] : null,
                ]),
            ]),
        ]);
    }

    public function cancel(Request $request, CustomerOpeningAdvance $customerAdvance)
    {
        $this->authorize('accounting.manage');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->service->cancel($customerAdvance, $data['reason']);
        return back()->with('success', 'Đã hủy khoản ứng trước khách hàng.');
    }
}
