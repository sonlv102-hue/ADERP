<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\Supplier;
use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierOpeningAdvance;
use App\Services\AccountingSettings;
use App\Services\CashVoucherService;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierAdvanceController extends Controller
{
    public function __construct(
        private SupplierAdvanceService $service,
        private CashVoucherService $cashVoucherService,
    ) {}

    public function index(Request $request)
    {
        $query = SupplierOpeningAdvance::with('supplier')
            ->withCount(['activeAllocations'])
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
            ->when($request->from_date, fn ($q) => $q->where('opening_date', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->where('opening_date', '<=', $request->to_date))
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
                'refunded_amount'  => (float) $adv->refunded_amount,
                'allocated_amount' => round((float) $adv->amount - (float) $adv->remaining_amount - (float) $adv->refunded_amount, 2),
                'reference_no'     => $adv->reference_no,
                'status'           => $adv->status,
                'status_label'     => $adv->statusLabel(),
                'can_refund'       => in_array($adv->status, ['open', 'partially_applied']) && (float) $adv->remaining_amount > 0,
                'can_cancel'       => $adv->status !== 'cancelled' && $adv->active_allocations_count === 0 && (float) $adv->refunded_amount <= 0,
                'can_delete'       => in_array($adv->status, ['open', 'cancelled']) && $adv->active_allocations_count === 0,
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
            'summary' => [
                'open'            => SupplierOpeningAdvance::whereIn('status', ['open', 'partially_applied'])->count(),
                'closed'          => SupplierOpeningAdvance::whereIn('status', ['fully_applied', 'cancelled'])->count(),
                'total_remaining' => (float) SupplierOpeningAdvance::whereIn('status', ['open', 'partially_applied'])->sum('remaining_amount'),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Purchasing/SupplierAdvances/Form', [
            'funds' => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
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
            // Prepayment: bắt buộc chọn quỹ/ngân hàng để tạo phiếu chi + bút toán Dr 331UT / Cr fund
            $rules['fiscal_year']    = ['nullable'];
            $rules['fund_id']        = ['required', Rule::exists('funds', 'id')->where('is_active', true)];
            $rules['payment_method'] = ['required', 'in:cash,bank_transfer'];
        }

        $data = $request->validate($rules);

        try {
            $advance = DB::transaction(function () use ($data, $advanceType) {
                if ($advanceType === 'prepayment') {
                    $data['account_code'] = AccountingSettings::get('supplier_advance_account', '331UT');
                }
                $advance = $this->service->create($data);

                if ($advanceType === 'prepayment') {
                    $fund = Fund::findOrFail($data['fund_id']);
                    $voucher = CashVoucher::create([
                        'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
                        'type'           => CashVoucherType::Payment,
                        'status'         => CashVoucherStatus::Draft,
                        'fund_id'        => $fund->id,
                        'supplier_id'    => $advance->supplier_id,
                        'partner_type'   => 'supplier',
                        'amount'         => $advance->amount,
                        'voucher_date'   => $advance->opening_date,
                        'description'    => 'Trả trước NCC' . ($advance->reference_no ? " {$advance->reference_no}" : ''),
                        'business_type'  => CashVoucherBusinessType::SupplierPrepayment->value,
                        'reference_type' => SupplierOpeningAdvance::class,
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

        $msg = $advanceType === 'prepayment'
            ? 'Đã tạo khoản trả trước và ghi sổ phiếu chi.'
            : 'Đã tạo khoản ứng trước đầu kỳ.';

        return redirect()->route('purchasing.supplier-advances.show', $advance)
            ->with('success', $msg);
    }

    public function show(SupplierOpeningAdvance $supplierAdvance)
    {
        $supplierAdvance->load([
            'supplier', 'creator',
            'allocations' => fn ($q) =>
                $q->with(['invoice', 'creator', 'reversedBy'])
                  ->orderByDesc('allocation_date')->orderByDesc('id'),
        ]);

        $refunds = SupplierAdvanceRefund::where('supplier_advance_id', $supplierAdvance->id)
            ->with(['creator', 'fund', 'bankAccount'])
            ->orderByDesc('refund_date')->orderByDesc('id')
            ->get();

        $allocatedAmount = round((float) $supplierAdvance->amount
            - (float) $supplierAdvance->remaining_amount
            - (float) $supplierAdvance->refunded_amount, 2);

        return Inertia::render('Purchasing/SupplierAdvances/Show', [
            'advance'      => array_merge($supplierAdvance->toArray(), [
                'type_label'       => $supplierAdvance->typeLabel(),
                'status_label'     => $supplierAdvance->statusLabel(),
                'allocated_amount' => $allocatedAmount,
                'can_refund'       => in_array($supplierAdvance->status, ['open', 'partially_applied'])
                    && (float) $supplierAdvance->remaining_amount > 0,
                'can_cancel'       => $supplierAdvance->status !== 'cancelled'
                    && ! $supplierAdvance->activeAllocations()->exists()
                    && (float) $supplierAdvance->refunded_amount <= 0,
                'can_delete'       => in_array($supplierAdvance->status, ['open', 'cancelled'])
                    && ! $supplierAdvance->activeAllocations()->exists(),
                'allocations'      => $supplierAdvance->allocations->map(fn ($a) => [
                    'id'                  => $a->id,
                    'allocation_date'     => $a->allocation_date,
                    'purchase_invoice_id' => $a->purchase_invoice_id,
                    'allocated_amount'    => (float) $a->allocated_amount,
                    'reason'              => $a->reason,
                    'status'              => $a->status,
                    'invoice'             => $a->invoice ? ['code' => $a->invoice->code] : null,
                    'creator'             => $a->creator ? ['name' => $a->creator->name] : null,
                ]),
            ]),
            'refunds'      => $refunds->map(fn ($r) => [
                'id'            => $r->id,
                'refund_date'   => $r->refund_date->format('d/m/Y'),
                'amount'        => (float) $r->amount,
                'refund_method' => $r->refund_method,
                'source_name'   => $r->fund?->name ?? $r->bankAccount?->name ?? '—',
                'description'   => $r->description,
                'status'        => $r->status,
                'creator'       => $r->creator?->name,
            ]),
            'funds'        => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'account_code']),
            'bankAccounts' => BankAccount::orderBy('name')->get(['id', 'name', 'account_number', 'account_code']),
        ]);
    }

    public function edit(SupplierOpeningAdvance $supplierAdvance)
    {
        return Inertia::render('Purchasing/SupplierAdvances/Form', [
            'advance' => $supplierAdvance->load('supplier'),
            'funds'   => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
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
        try {
            $this->service->cancel($supplierAdvance, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
        return back()->with('success', 'Đã hủy khoản ứng trước.');
    }

    public function refund(Request $request, SupplierOpeningAdvance $supplierAdvance)
    {
        $data = $request->validate([
            'refund_date'     => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:1'],
            'refund_method'   => ['required', 'in:cash,bank'],
            'fund_id'         => ['required_if:refund_method,cash', 'nullable', 'exists:funds,id'],
            'bank_account_id' => ['required_if:refund_method,bank', 'nullable', 'exists:bank_accounts,id'],
            'description'     => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->refund($supplierAdvance, $data);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('success', 'Đã thu hồi tiền trả trước NCC. Bút toán Dr ' .
            ($data['refund_method'] === 'cash' ? '1111' : '1121') . ' / Cr 331UT đã được tạo.');
    }

    public function destroy(Request $request, SupplierOpeningAdvance $supplierAdvance)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->deleteSafely($supplierAdvance, $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('purchasing.supplier-advances.index')
            ->with('success', 'Đã xóa khoản trả trước NCC. Bút toán liên quan đã được xử lý.');
    }
}
