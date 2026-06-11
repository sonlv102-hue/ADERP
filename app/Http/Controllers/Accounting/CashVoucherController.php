<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\CashVoucherType;
use App\Http\Controllers\Controller;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\Supplier;
use App\Services\CashVoucherService;
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
            ->orderByDesc('voucher_date')
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
            'id'           => $v->id,
            'code'         => $v->code,
            'type'         => $v->type->value,
            'type_label'   => $v->type->label(),
            'type_color'   => $v->type->color(),
            'status'       => $v->status->value,
            'status_label' => $v->status->label(),
            'status_color' => $v->status->color(),
            'fund'         => $v->fund?->name,
            'amount'       => (float) $v->amount,
            'voucher_date' => $v->voucher_date?->format('d/m/Y'),
            'counterparty' => $v->counterparty,
            'description'  => $v->description,
            'creator'      => $v->creator?->name,
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

        return Inertia::render('Accounting/CashVouchers/Form', [
            'voucher'     => null,
            'nextCode'    => CashVoucher::generateCode($type),
            'funds'       => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'defaultType' => $type->value,
            'suppliers'   => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type'         => 'required|in:receipt,payment',
            'fund_id'      => 'required|exists:funds,id',
            'amount'       => 'required|numeric|min:0.01',
            'voucher_date' => 'required|date',
            'counterparty' => 'nullable|string|max:255',
            'supplier_id'  => 'nullable|exists:suppliers,id',
            'description'  => 'required|string|max:500',
        ]);

        $type = CashVoucherType::from($data['type']);
        $data['code']       = CashVoucher::generateCode($type);
        $data['created_by'] = auth()->id();

        CashVoucher::create($data);

        return redirect()->route('accounting.cash-vouchers.index')
            ->with('success', 'Phiếu đã được tạo.');
    }

    public function show(CashVoucher $cashVoucher): Response
    {
        $cashVoucher->load('fund', 'creator');

        return Inertia::render('Accounting/CashVouchers/Show', [
            'voucher' => [
                'id'           => $cashVoucher->id,
                'code'         => $cashVoucher->code,
                'type'         => $cashVoucher->type->value,
                'type_label'   => $cashVoucher->type->label(),
                'type_color'   => $cashVoucher->type->color(),
                'status'       => $cashVoucher->status->value,
                'status_label' => $cashVoucher->status->label(),
                'status_color' => $cashVoucher->status->color(),
                'fund'         => $cashVoucher->fund?->name,
                'amount'       => (float) $cashVoucher->amount,
                'voucher_date' => $cashVoucher->voucher_date?->format('d/m/Y'),
                'counterparty' => $cashVoucher->counterparty,
                'description'  => $cashVoucher->description,
                'creator'      => $cashVoucher->creator?->name,
                'created_at'   => $cashVoucher->created_at?->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function edit(CashVoucher $cashVoucher): Response
    {
        if ($cashVoucher->status->value !== 'draft') {
            return redirect()->route('accounting.cash-vouchers.show', $cashVoucher)
                ->with('error', 'Chỉ sửa được phiếu ở trạng thái nháp.');
        }

        return Inertia::render('Accounting/CashVouchers/Form', [
            'voucher' => [
                'id'           => $cashVoucher->id,
                'code'         => $cashVoucher->code,
                'type'         => $cashVoucher->type->value,
                'fund_id'      => $cashVoucher->fund_id,
                'amount'       => (float) $cashVoucher->amount,
                'voucher_date' => $cashVoucher->voucher_date?->format('Y-m-d'),
                'counterparty' => $cashVoucher->counterparty,
                'supplier_id'  => $cashVoucher->supplier_id,
                'description'  => $cashVoucher->description,
            ],
            'funds'       => Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'defaultType' => $cashVoucher->type->value,
            'suppliers'   => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, CashVoucher $cashVoucher): RedirectResponse
    {
        if ($cashVoucher->status->value !== 'draft') {
            return back()->with('error', 'Chỉ sửa được phiếu ở trạng thái nháp.');
        }

        $data = $request->validate([
            'fund_id'      => 'required|exists:funds,id',
            'amount'       => 'required|numeric|min:0.01',
            'voucher_date' => 'required|date',
            'counterparty' => 'nullable|string|max:255',
            'supplier_id'  => 'nullable|exists:suppliers,id',
            'description'  => 'required|string|max:500',
        ]);

        $cashVoucher->update($data);

        return redirect()->route('accounting.cash-vouchers.show', $cashVoucher)
            ->with('success', 'Phiếu đã được cập nhật.');
    }

    public function confirm(CashVoucher $cashVoucher): RedirectResponse
    {
        try {
            $this->service->confirm($cashVoucher);
            return back()->with('success', 'Phiếu đã được xác nhận.');
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
            return back()->with('error', 'Không thể xóa phiếu đã xác nhận. Hủy phiếu trước.');
        }

        $cashVoucher->delete();

        return redirect()->route('accounting.cash-vouchers.index')
            ->with('success', 'Đã xóa phiếu.');
    }
}
