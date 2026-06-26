<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Project;
use App\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function __construct(private CommissionService $service) {}

    public function index(): Response
    {
        $search    = request('search');
        $status    = request('status');
        $type      = request('type');
        $orderCode = request('order_code');

        return Inertia::render('Sales/Commissions/Index', [
            'commissions' => Commission::with(['creator', 'customer', 'order', 'project'])
                ->when($search, fn ($q) => $q->where(fn ($q2) =>
                    $q2->where('code', 'ilike', "%{$search}%")
                       ->orWhere('recipient_name', 'ilike', "%{$search}%")
                ))
                ->when($status,    fn ($q) => $q->where('status', $status))
                ->when($type,      fn ($q) => $q->where('type', $type))
                ->when($orderCode, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('code', 'ilike', "%{$orderCode}%")))
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($c) => $this->formatRow($c)),
            'types'    => collect(CommissionType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'statuses' => collect(CommissionStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'filters'  => ['search' => $search, 'status' => $status, 'type' => $type, 'order_code' => $orderCode],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Sales/Commissions/Form', [
            'nextCode' => Commission::generateCode(),
            'types'    => collect(CommissionType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'projects' => Project::orderByDesc('id')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'unique:commissions,code'],
            'type'           => ['required', 'string'],
            'customer_id'    => ['nullable', 'exists:customers,id'],
            'order_id'       => ['nullable', 'exists:orders,id'],
            'project_id'     => ['nullable', 'exists:projects,id'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'recipient_info' => ['nullable', 'string'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['required', 'in:cash,bank_transfer,other'],
            'planned_date'   => ['nullable', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $commission = Commission::create([
            ...$data,
            'status'     => CommissionStatus::Draft,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('sales.commissions.show', $commission)
            ->with('success', 'Đã tạo khoản hoa hồng.');
    }

    public function show(Commission $commission): Response
    {
        $commission->load(['creator', 'customer', 'order', 'project', 'approver1', 'approver2', 'payer']);

        return Inertia::render('Sales/Commissions/Show', [
            'commission' => $this->formatDetail($commission),
        ]);
    }

    public function edit(Commission $commission): Response
    {
        abort_unless($commission->status === CommissionStatus::Draft, 403, 'Chỉ có thể sửa khi ở trạng thái Nháp.');

        $commission->loadMissing(['customer', 'order']);
        return Inertia::render('Sales/Commissions/Form', [
            'commission' => $commission,
            'types'      => collect(CommissionType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()]),
            'projects'   => Project::orderByDesc('id')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, Commission $commission): RedirectResponse
    {
        abort_unless($commission->status === CommissionStatus::Draft, 403);

        $data = $request->validate([
            'type'           => ['required', 'string'],
            'customer_id'    => ['nullable', 'exists:customers,id'],
            'order_id'       => ['nullable', 'exists:orders,id'],
            'project_id'     => ['nullable', 'exists:projects,id'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'recipient_info' => ['nullable', 'string'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['required', 'in:cash,bank_transfer,other'],
            'planned_date'   => ['nullable', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $commission->update($data);

        return redirect()->route('sales.commissions.show', $commission)
            ->with('success', 'Đã cập nhật khoản hoa hồng.');
    }

    public function submit(Commission $commission): RedirectResponse
    {
        try {
            $this->service->submit($commission);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã trình duyệt.');
    }

    public function approveL1(Commission $commission): RedirectResponse
    {
        $this->authorize('commissions.approve_l1');
        try {
            $this->service->approveL1($commission);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Trưởng phòng đã duyệt — chờ giám đốc duyệt tiếp.');
    }

    public function approveL2(Commission $commission): RedirectResponse
    {
        $this->authorize('commissions.approve');
        try {
            $this->service->approveL2($commission);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Giám đốc đã duyệt — chờ kế toán thanh toán.');
    }

    public function reject(Request $request, Commission $commission): RedirectResponse
    {
        $data = $request->validate([
            'reject_reason' => ['required', 'string', 'max:500'],
        ]);
        try {
            $this->service->reject($commission, $data['reject_reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã từ chối khoản hoa hồng.');
    }

    public function pay(Request $request, Commission $commission): RedirectResponse
    {
        $this->authorize('commissions.pay');
        $data = $request->validate([
            'paid_date' => ['required', 'date'],
        ]);
        try {
            $this->service->pay($commission, $data['paid_date']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã ghi nhận thanh toán hoa hồng.');
    }

    public function cancel(Commission $commission): RedirectResponse
    {
        try {
            $this->service->cancel($commission);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Đã hủy khoản hoa hồng.');
    }

    public function destroy(Commission $commission): RedirectResponse
    {
        if (! in_array($commission->status, [CommissionStatus::Draft, CommissionStatus::Cancelled])) {
            return back()->with('error', 'Chỉ có thể xóa khoản hoa hồng ở trạng thái nháp hoặc đã hủy.');
        }

        $commission->delete();

        return redirect()->route('sales.commissions.index')
            ->with('success', 'Đã xóa khoản hoa hồng.');
    }

    private function formatRow(Commission $c): array
    {
        return [
            'id'             => $c->id,
            'code'           => $c->code,
            'type_label'     => $c->type->label(),
            'recipient_name' => $c->recipient_name,
            'amount'         => $c->amount,
            'status'         => $c->status->value,
            'status_label'   => $c->status->label(),
            'status_color'   => $c->status->color(),
            'customer'       => $c->customer?->name,
            'order'          => $c->order?->code,
            'project'        => $c->project?->code,
            'planned_date'   => $c->planned_date?->format('d/m/Y'),
            'creator'        => $c->creator->name,
        ];
    }

    private function formatDetail(Commission $c): array
    {
        return [
            'id'             => $c->id,
            'code'           => $c->code,
            'type'           => $c->type->value,
            'type_label'     => $c->type->label(),
            'status'         => $c->status->value,
            'status_label'   => $c->status->label(),
            'status_color'   => $c->status->color(),
            'recipient_name' => $c->recipient_name,
            'recipient_info' => $c->recipient_info,
            'amount'         => $c->amount,
            'rate'           => $c->rate,
            'payment_method' => $c->payment_method,
            'payment_method_label' => match($c->payment_method) {
                'cash'          => 'Tiền mặt',
                'bank_transfer' => 'Chuyển khoản',
                default         => 'Khác',
            },
            'planned_date'   => $c->planned_date?->format('d/m/Y'),
            'paid_date'      => $c->paid_date?->format('d/m/Y'),
            'reject_reason'  => $c->reject_reason,
            'notes'          => $c->notes,
            'customer'       => $c->customer?->name,
            'customer_id'    => $c->customer_id,
            'order'          => $c->order?->code,
            'order_id'       => $c->order_id,
            'project'        => $c->project?->code,
            'project_id'     => $c->project_id,
            'creator'        => $c->creator->name,
            'created_by'     => $c->created_by,
            'approver1'      => $c->approver1?->name,
            'approved1_at'   => $c->approved1_at?->format('d/m/Y H:i'),
            'approver2'      => $c->approver2?->name,
            'approved2_at'   => $c->approved2_at?->format('d/m/Y H:i'),
            'payer'          => $c->payer?->name,
            'paid_at'        => $c->paid_at?->format('d/m/Y H:i'),
        ];
    }
}
