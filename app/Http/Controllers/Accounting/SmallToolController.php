<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SmallToolStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('ccdc.view');

        $query = SmallTool::with(['category', 'responsibleEmployee', 'warehouse'])
            ->when($request->search, fn ($q, $s) =>
                $q->where(fn ($q2) => $q2->where('code', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%"))
            )
            ->when($request->status,      fn ($q) => $q->where('status', $request->status))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->department,  fn ($q) => $q->where('department', 'ilike', '%' . $request->department . '%'))
            ->when($request->project_id,  fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->warehouse_id, fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->orderByDesc('id');

        $tools = $query->paginate(50)->through(fn (SmallTool $t) => $this->toDto($t));

        return Inertia::render('Accounting/SmallTools/Index', [
            'tools'       => $tools,
            'categories'  => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'statuses'    => collect(SmallToolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'warehouses'  => Warehouse::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['search', 'status', 'category_id', 'department', 'project_id', 'warehouse_id']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('ccdc.manage');
        return Inertia::render('Accounting/SmallTools/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $this->validateTool($request);
        $data['code']       = SmallTool::generateCode();
        $data['created_by'] = auth()->id();
        $data['status']     = 'draft';

        $tool = SmallTool::create($data);

        // Nếu mua dùng ngay: confirm luôn
        if ($request->auto_confirm && $tool->acquisition_type === 'direct') {
            $this->service->confirmDirectTool($tool);
        }

        return redirect()->route('accounting.small-tools.show', $tool)
            ->with('success', 'Đã tạo hồ sơ CCDC.');
    }

    public function show(SmallTool $tool): Response
    {
        $this->authorize('ccdc.view');

        $tool->load([
            'category', 'responsibleEmployee', 'warehouse', 'project',
            'supplier', 'purchaseInvoice', 'fund',
            'allocations', 'transfers.fromEmployee', 'transfers.toEmployee',
            'transfers.fromProject', 'transfers.toProject',
            'disposals', 'pausedByUser', 'resumedByUser',
        ]);

        $history = \Spatie\Activitylog\Models\Activity::forSubject($tool)
            ->with('causer')->latest()->get()->map(fn ($log) => [
                'description' => $log->description,
                'causer_name' => $log->causer?->name ?? 'Hệ thống',
                'created_at'  => $log->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Accounting/SmallTools/Show', [
            'tool'        => $this->toDto($tool),
            'history'     => $history,
            'allocations' => $tool->allocations->map(fn ($a) => [
                'id'                 => $a->id,
                'period'             => $a->period,
                'amount'             => (float) $a->amount,
                'accumulated_before' => (float) $a->accumulated_before,
                'remaining_after'    => (float) $a->remaining_after,
                'debit_account'      => $a->debit_account,
                'credit_account'     => $a->credit_account,
                'status'             => $a->status,
                'posted_at'          => $a->posted_at?->format('Y-m-d'),
            ]),
            'transfers'   => $tool->transfers->map(fn ($t) => [
                'id'              => $t->id,
                'code'            => $t->code,
                'transfer_date'   => $t->transfer_date->format('Y-m-d'),
                'from_department' => $t->from_department,
                'to_department'   => $t->to_department,
                'from_employee'   => $t->fromEmployee?->name,
                'to_employee'     => $t->toEmployee?->name,
                'from_project'    => $t->fromProject?->name,
                'to_project'      => $t->toProject?->name,
                'reason'          => $t->reason,
            ]),
            'disposals'   => $tool->disposals->map(fn ($d) => [
                'id'           => $d->id,
                'code'         => $d->code,
                'disposal_type' => $d->disposal_type,
                'type_label'   => $d->disposalTypeLabel(),
                'disposal_date' => $d->disposal_date->format('Y-m-d'),
                'net_value'    => (float) $d->net_value_snapshot,
                'status'       => $d->status,
                'reason'       => $d->reason,
            ]),
            'statuses'    => collect(SmallToolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function edit(SmallTool $tool): Response
    {
        $this->authorize('ccdc.manage');
        return Inertia::render('Accounting/SmallTools/Form', array_merge(
            $this->formProps(),
            ['tool' => $this->toDto($tool)]
        ));
    }

    public function update(Request $request, SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        if (! in_array($tool->status->value, ['draft', 'in_stock'])) {
            return back()->withErrors(['error' => 'Không thể sửa CCDC đang sử dụng hoặc đã xử lý.']);
        }

        $data = $this->validateTool($request, $tool);
        $data['updated_by'] = auth()->id();
        $tool->update($data);

        return redirect()->route('accounting.small-tools.show', $tool)
            ->with('success', 'Đã cập nhật hồ sơ CCDC.');
    }

    public function confirm(SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');
        $this->service->confirmDirectTool($tool);
        return back()->with('success', 'Đã ghi nhận bút toán CCDC dùng ngay.');
    }

    private function toDto(SmallTool $t): array
    {
        return [
            'id'                           => $t->id,
            'code'                         => $t->code,
            'name'                         => $t->name,
            'category_id'                  => $t->category_id,
            'category_name'                => $t->category?->name,
            'unit'                         => $t->unit,
            'quantity'                     => $t->quantity,
            'original_cost'                => (float) $t->original_cost,
            'vat_amount'                   => (float) $t->vat_amount,
            'total_cost'                   => (float) $t->total_cost,
            'acquisition_type'             => $t->acquisition_type,
            'recognition_method'           => $t->recognition_method,
            'allocation_periods'           => $t->allocation_periods,
            'allocation_start_date'        => $t->allocation_start_date?->format('Y-m-d'),
            'purchase_date'                => $t->purchase_date?->format('Y-m-d'),
            'in_service_date'              => $t->in_service_date?->format('Y-m-d'),
            'department'                   => $t->department,
            'responsible_employee_id'      => $t->responsible_employee_id,
            'responsible_employee_name'    => $t->responsibleEmployee?->name,
            'warehouse_id'                 => $t->warehouse_id,
            'warehouse_name'               => $t->warehouse?->name,
            'project_id'                   => $t->project_id,
            'project_name'                 => $t->project?->name,
            'supplier_id'                  => $t->supplier_id,
            'supplier_name'                => $t->supplier?->name,
            'purchase_invoice_id'          => $t->purchase_invoice_id,
            'payment_type'                 => $t->payment_type,
            'fund_id'                      => $t->fund_id,
            'stock_account_code'           => $t->stock_account_code,
            'pending_account_code'         => $t->pending_account_code,
            'expense_account_code'         => $t->expense_account_code,
            'payable_account_code'         => $t->payable_account_code,
            'periods_allocated'            => $t->periods_allocated,
            'total_allocated'              => (float) $t->total_allocated,
            'total_remaining'              => $t->total_remaining,
            'monthly_allocation_amount'    => $t->monthly_allocation_amount,
            'acquisition_journal_entry_id' => $t->acquisition_journal_entry_id,
            'issue_journal_entry_id'       => $t->issue_journal_entry_id,
            'status'                       => $t->status->value,
            'status_label'                 => $t->status->label(),
            'status_color'                 => $t->status->color(),
            'notes'                        => $t->notes,
            'is_opening_balance'           => $t->is_opening_balance,
            'opening_balance_period'       => $t->opening_balance_period,
            'allocation_status'            => $t->allocation_status,
            'pause_reason'                 => $t->pause_reason,
            'pause_effective_period'       => $t->pause_effective_period,
            'paused_at'                    => $t->paused_at?->format('d/m/Y H:i'),
            'paused_by_name'               => $t->pausedByUser?->name,
            'resumed_at'                   => $t->resumed_at?->format('d/m/Y H:i'),
            'resumed_by_name'              => $t->resumedByUser?->name,
            'can_pause'                    => $t->canPauseAllocation(),
            'can_resume'                   => $t->canResumeAllocation(),
        ];
    }

    private function formProps(): array
    {
        return [
            'nextCode'   => SmallTool::generateCode(),
            'categories' => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'employees'  => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'projects'   => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get(['id', 'name', 'code']),
            'funds'      => Fund::orderBy('name')->get(['id', 'name', 'type', 'account_code']),
        ];
    }

    private function validateTool(Request $request, ?SmallTool $existing = null): array
    {
        return $request->validate([
            'name'                    => 'required|string|max:255',
            'category_id'             => 'nullable|exists:small_tool_categories,id',
            'unit'                    => 'nullable|string|max:30',
            'quantity'                => 'required|integer|min:1',
            'original_cost'           => 'required|numeric|min:0',
            'vat_amount'              => 'nullable|numeric|min:0',
            'total_cost'              => 'nullable|numeric|min:0',
            'acquisition_type'        => 'required|in:stock,direct',
            'recognition_method'      => 'required|in:immediate,allocation',
            'allocation_periods'      => 'nullable|integer|min:1',
            'allocation_start_date'   => 'nullable|date',
            'purchase_date'           => 'nullable|date',
            'in_service_date'         => 'nullable|date',
            'department'              => 'nullable|string|max:100',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'warehouse_id'            => 'nullable|exists:warehouses,id',
            'project_id'              => 'nullable|exists:projects,id',
            'supplier_id'             => 'nullable|exists:suppliers,id',
            'purchase_invoice_id'     => 'nullable|exists:purchase_invoices,id',
            'payment_type'            => 'required|in:payable,cash,bank',
            'fund_id'                 => 'nullable|exists:funds,id',
            'stock_account_code'      => 'nullable|string|max:20',
            'pending_account_code'    => 'nullable|string|max:20',
            'expense_account_code'    => 'nullable|string|max:20',
            'payable_account_code'    => 'nullable|string|max:20',
            'notes'                   => 'nullable|string',
        ]);
    }
}
