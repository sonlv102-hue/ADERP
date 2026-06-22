<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherType;
use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\CashVoucher;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use App\Services\CashVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashVoucherController extends Controller
{
    public function __construct(private CashVoucherService $service) {}

    public function index(Request $request): Response
    {
        $query = CashVoucher::with('fund', 'creator')
            ->orderByDesc('id');

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($fundId = $request->input('fund_id')) {
            $query->where('fund_id', $fundId);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('counterparty', 'ilike', "%{$search}%");
            });
        }

        $vouchers = $query->paginate(20)->through(fn (CashVoucher $v) => [
            'id'            => $v->id,
            'code'          => $v->code,
            'type'          => $v->type->value,
            'type_label'    => $v->type->label(),
            'type_color'    => $v->type->color(),
            'status'        => $v->status->value,
            'status_label'  => $v->status->label(),
            'status_color'  => $v->status->color(),
            'fund'          => $v->fund?->name,
            'amount'        => (float) $v->amount,
            'voucher_date'  => $v->voucher_date?->format('d/m/Y'),
            'counterparty'  => $v->counterparty,
            'description'   => $v->description,
            'business_type' => $v->business_type,
            'journal_mode'  => $v->journal_mode,
            'creator'       => $v->creator?->name,
        ]);

        return Inertia::render('Accounting/CashVouchers/Index', [
            'vouchers' => $vouchers,
            'funds'    => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'filters'  => $request->only(['type', 'status', 'fund_id', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = CashVoucherType::from($request->input('type', 'receipt'));

        return Inertia::render('Accounting/CashVouchers/Form', $this->formProps($type));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateVoucherRequest($request);
        $type = CashVoucherType::from($data['type']);

        $data['code']       = CashVoucher::generateCode($type);
        $data['created_by'] = auth()->id();

        $voucher = CashVoucher::create($data);

        $this->saveLines($voucher, $request->input('lines', []));

        return redirect()->route('accounting.cash-vouchers.show', $voucher)
            ->with('success', 'Phiếu đã được tạo.');
    }

    public function show(CashVoucher $cashVoucher): Response
    {
        $cashVoucher->load('fund', 'creator', 'customer', 'supplier', 'employee', 'journalLines');

        // Lấy JE liên kết (mới nhất, posted)
        $je = \App\Models\JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $cashVoucher->id)
            ->where('status', 'posted')
            ->latest()
            ->first();

        return Inertia::render('Accounting/CashVouchers/Show', [
            'voucher' => $this->voucherDto($cashVoucher),
            'lines'   => $cashVoucher->journalLines->map(fn ($l) => [
                'id'             => $l->id,
                'debit_account'  => $l->debit_account,
                'credit_account' => $l->credit_account,
                'amount'         => (float) $l->amount,
                'description'    => $l->description,
                'partner_type'   => $l->partner_type,
                'partner_id'     => $l->partner_id,
                'partner_name'   => $l->partnerName(),
            ]),
            'journal_entry_code' => $je?->code,
        ]);
    }

    public function edit(CashVoucher $cashVoucher): Response
    {
        if ($cashVoucher->status->value !== 'draft') {
            return redirect()->route('accounting.cash-vouchers.show', $cashVoucher)
                ->with('error', 'Chỉ sửa được phiếu ở trạng thái nháp.');
        }

        $cashVoucher->load(['journalLines', 'supplier', 'customer', 'employee']);
        $type = $cashVoucher->type;

        // Batch-resolve partner names for manual JE lines
        $linePartners = $this->resolveLinePartnerNames($cashVoucher->journalLines);

        return Inertia::render('Accounting/CashVouchers/Form', array_merge(
            $this->formProps($type),
            [
                'voucher' => [
                    'id'              => $cashVoucher->id,
                    'code'            => $cashVoucher->code,
                    'type'            => $cashVoucher->type->value,
                    'fund_id'         => $cashVoucher->fund_id,
                    'amount'          => (float) $cashVoucher->amount,
                    'voucher_date'    => $cashVoucher->voucher_date?->format('Y-m-d'),
                    'counterparty'    => $cashVoucher->counterparty,
                    'partner_type'    => $cashVoucher->partner_type,
                    'supplier_id'     => $cashVoucher->supplier_id,
                    'supplier_name'   => $cashVoucher->supplier?->name,
                    'customer_id'     => $cashVoucher->customer_id,
                    'customer_name'   => $cashVoucher->customer?->name,
                    'employee_id'     => $cashVoucher->employee_id,
                    'employee_name'   => $cashVoucher->employee?->name,
                    'description'     => $cashVoucher->description,
                    'business_type'   => $cashVoucher->business_type,
                    'journal_mode'    => $cashVoucher->journal_mode,
                    'edited_by_user'  => $cashVoucher->edited_by_user,
                    'edit_reason'     => $cashVoucher->edit_reason,
                    'lines'           => $cashVoucher->journalLines->map(fn ($l) => [
                        'debit_account'   => $l->debit_account,
                        'credit_account'  => $l->credit_account,
                        'amount'          => (float) $l->amount,
                        'description'     => $l->description,
                        'partner_type'    => $l->partner_type,
                        'partner_id'      => $l->partner_id,
                        'partner_display' => $linePartners[$l->partner_type][$l->partner_id] ?? null,
                    ])->values()->toArray(),
                ],
            ]
        ));
    }

    public function update(Request $request, CashVoucher $cashVoucher): RedirectResponse
    {
        if ($cashVoucher->status->value !== 'draft') {
            return back()->with('error', 'Chỉ sửa được phiếu ở trạng thái nháp.');
        }

        $data = $this->validateVoucherRequest($request, isUpdate: true);
        $cashVoucher->update($data);

        $this->saveLines($cashVoucher, $request->input('lines', []));

        return redirect()->route('accounting.cash-vouchers.show', $cashVoucher)
            ->with('success', 'Phiếu đã được cập nhật.');
    }

    public function confirm(CashVoucher $cashVoucher): RedirectResponse
    {
        try {
            $this->service->confirm($cashVoucher);
            return back()->with('success', 'Phiếu đã được ghi sổ thành công.');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function unpost(CashVoucher $cashVoucher): RedirectResponse
    {
        try {
            $this->service->unpost($cashVoucher);
            return back()->with('success', 'Đã thu hồi ghi sổ. Phiếu về trạng thái nháp.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(CashVoucher $cashVoucher): RedirectResponse
    {
        try {
            $this->service->cancel($cashVoucher);
            return back()->with('success', 'Phiếu đã bị hủy.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(CashVoucher $cashVoucher): RedirectResponse
    {
        if ($cashVoucher->status->value === 'confirmed') {
            return back()->with('error', 'Không thể xóa phiếu đã ghi sổ. Thu hồi hoặc hủy trước.');
        }

        $cashVoucher->journalLines()->delete();
        $cashVoucher->delete();

        return redirect()->route('accounting.cash-vouchers.index')
            ->with('success', 'Đã xóa phiếu.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Batch-load partner names for JE lines to avoid N+1 in edit mode. */
    private function resolveLinePartnerNames(Collection $lines): array
    {
        $supplierIds = $lines->where('partner_type', 'supplier')->pluck('partner_id')->filter()->unique();
        $customerIds = $lines->where('partner_type', 'customer')->pluck('partner_id')->filter()->unique();
        $employeeIds = $lines->where('partner_type', 'employee')->pluck('partner_id')->filter()->unique();

        $suppliers = $supplierIds->isNotEmpty()
            ? Supplier::whereIn('id', $supplierIds)->pluck('name', 'id') : collect();
        $customers = $customerIds->isNotEmpty()
            ? Customer::whereIn('id', $customerIds)->pluck('name', 'id') : collect();
        $employees = $employeeIds->isNotEmpty()
            ? Employee::whereIn('id', $employeeIds)->pluck('name', 'id') : collect();

        return [
            'supplier' => $suppliers->toArray(),
            'customer' => $customers->toArray(),
            'employee' => $employees->toArray(),
        ];
    }

    private function formProps(CashVoucherType $type): array
    {
        $businessTypes = array_map(fn ($bt) => [
            'value' => $bt->value,
            'label' => $bt->label(),
        ], CashVoucherBusinessType::cases());

        $accountCodes = AccountCode::where('is_detail', true)
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name])
            ->toArray();

        return [
            'voucher'       => null,
            'nextCode'      => CashVoucher::generateCode($type),
            'funds'         => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'defaultType'   => $type->value,
            'businessTypes' => $businessTypes,
            'accountCodes'  => $accountCodes,
        ];
    }

    private function voucherDto(CashVoucher $cashVoucher): array
    {
        return [
            'id'              => $cashVoucher->id,
            'code'            => $cashVoucher->code,
            'type'            => $cashVoucher->type->value,
            'type_label'      => $cashVoucher->type->label(),
            'type_color'      => $cashVoucher->type->color(),
            'status'          => $cashVoucher->status->value,
            'status_label'    => $cashVoucher->status->label(),
            'status_color'    => $cashVoucher->status->color(),
            'fund'            => $cashVoucher->fund?->name,
            'amount'          => (float) $cashVoucher->amount,
            'voucher_date'    => $cashVoucher->voucher_date?->format('d/m/Y'),
            'counterparty'    => $cashVoucher->counterparty,
            'description'     => $cashVoucher->description,
            'business_type'   => $cashVoucher->business_type,
            'journal_mode'    => $cashVoucher->journal_mode,
            'edited_by_user'  => $cashVoucher->edited_by_user,
            'edit_reason'     => $cashVoucher->edit_reason,
            'creator'         => $cashVoucher->creator?->name,
            'created_at'      => $cashVoucher->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function validateVoucherRequest(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'fund_id'         => 'required|exists:funds,id',
            'amount'          => 'required|numeric|min:0.01',
            'voucher_date'    => 'required|date',
            'counterparty'    => 'nullable|string|max:255',
            'partner_type'    => 'nullable|in:supplier,customer,employee',
            'supplier_id'     => 'nullable|exists:suppliers,id',
            'customer_id'     => 'nullable|exists:customers,id',
            'employee_id'     => 'nullable|exists:employees,id',
            'description'     => 'required|string|max:500',
            'business_type'   => 'nullable|string|max:50',
            'journal_mode'    => 'nullable|in:auto,manual',
            'edited_by_user'  => 'nullable|boolean',
            'edit_reason'     => 'nullable|string|max:500',
            'lines'           => 'nullable|array',
            'lines.*.debit_account'  => 'required_with:lines|string|max:20',
            'lines.*.credit_account' => 'required_with:lines|string|max:20',
            'lines.*.amount'         => 'required_with:lines|numeric|min:0.01',
            'lines.*.description'    => 'nullable|string|max:500',
            'lines.*.partner_type'   => 'nullable|in:supplier,customer,employee',
            'lines.*.partner_id'     => 'nullable|integer|min:1',
        ];

        if (! $isUpdate) {
            $rules['type'] = 'required|in:receipt,payment';
        }

        return $request->validate($rules);
    }

    private function saveLines(CashVoucher $voucher, array $lines): void
    {
        $voucher->journalLines()->delete();

        foreach ($lines as $i => $line) {
            $voucher->journalLines()->create([
                'debit_account'  => $line['debit_account'],
                'credit_account' => $line['credit_account'],
                'amount'         => $line['amount'],
                'description'    => $line['description'] ?? null,
                'partner_type'   => $line['partner_type'] ?? null,
                'partner_id'     => $line['partner_id'] ?? null,
                'sort_order'     => $i,
            ]);
        }
    }
}
