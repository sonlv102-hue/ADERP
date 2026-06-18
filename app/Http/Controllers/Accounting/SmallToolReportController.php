<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SmallToolStatus;
use App\Http\Controllers\Controller;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolReportController extends Controller
{
    // Sổ CCDC — danh sách tổng hợp
    public function ledger(Request $request): Response
    {
        $this->authorize('reports.view');

        $query = SmallTool::with(['category', 'responsibleEmployee', 'project'])
            ->when($request->status,      fn ($q) => $q->where('status', $request->status))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->department,  fn ($q) => $q->where('department', 'ilike', '%' . $request->department . '%'))
            ->when($request->project_id,  fn ($q) => $q->where('project_id', $request->project_id))
            ->orderBy('code');

        $tools = $query->get()->map(fn (SmallTool $t) => [
            'code'               => $t->code,
            'name'               => $t->name,
            'category_name'      => $t->category?->name,
            'unit'               => $t->unit,
            'quantity'           => $t->quantity,
            'original_cost'      => (float) $t->original_cost,
            'total_allocated'    => (float) $t->total_allocated,
            'total_remaining'    => $t->total_remaining,
            'periods_allocated'  => $t->periods_allocated,
            'allocation_periods' => $t->allocation_periods,
            'department'         => $t->department,
            'employee_name'      => $t->responsibleEmployee?->name,
            'project_name'       => $t->project?->name,
            'in_service_date'    => $t->in_service_date?->format('Y-m-d'),
            'status_label'       => $t->status->label(),
            'status_color'       => $t->status->color(),
            'expense_account'    => $t->expense_account_code,
        ]);

        return Inertia::render('Accounting/SmallTools/Reports/Ledger', [
            'tools'      => $tools,
            'categories' => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'statuses'   => collect(SmallToolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'filters'    => $request->only(['status', 'category_id', 'department', 'project_id']),
            'summary'    => [
                'total_count'     => $tools->count(),
                'total_cost'      => $tools->sum('original_cost'),
                'total_allocated' => $tools->sum('total_allocated'),
                'total_remaining' => $tools->sum('total_remaining'),
            ],
        ]);
    }

    // Bảng phân bổ CCDC
    public function allocationSchedule(Request $request): Response
    {
        $this->authorize('reports.view');

        $period = $request->period ?? now()->format('Y-m');

        $allocations = SmallToolAllocation::with('tool')
            ->where('period', $period)
            ->orderBy('small_tool_id')
            ->get()
            ->map(fn ($a) => [
                'tool_code'          => $a->tool->code,
                'tool_name'          => $a->tool->name,
                'period'             => $a->period,
                'amount'             => (float) $a->amount,
                'accumulated_before' => (float) $a->accumulated_before,
                'remaining_after'    => (float) $a->remaining_after,
                'debit_account'      => $a->debit_account,
                'credit_account'     => $a->credit_account,
                'status'             => $a->status,
                'posted_at'          => $a->posted_at?->format('Y-m-d'),
            ]);

        return Inertia::render('Accounting/SmallTools/Reports/AllocationSchedule', [
            'period'      => $period,
            'allocations' => $allocations,
            'total'       => $allocations->sum('amount'),
        ]);
    }

    // Đối soát GL
    public function glReconcile(Request $request): Response
    {
        $this->authorize('reports.view');

        $tools = SmallTool::with('allocations')->get()->map(fn (SmallTool $t) => [
            'code'               => $t->code,
            'name'               => $t->name,
            'original_cost'      => (float) $t->original_cost,
            'total_allocated'    => (float) $t->total_allocated,
            'total_remaining'    => $t->total_remaining,
            'posted_periods'     => $t->allocations->where('status', 'posted')->count(),
            'allocation_periods' => $t->allocation_periods,
            'status'             => $t->status->value,
            'status_label'       => $t->status->label(),
            'stock_account'      => $t->stock_account_code,
            'pending_account'    => $t->pending_account_code,
            'expense_account'    => $t->expense_account_code,
            'warnings'           => $this->detectWarnings($t),
        ]);

        return Inertia::render('Accounting/SmallTools/Reports/GlReconcile', [
            'tools'         => $tools,
            'warningCount'  => $tools->sum(fn ($t) => count($t['warnings'])),
        ]);
    }

    private function detectWarnings(SmallTool $tool): array
    {
        $warnings = [];

        if ($tool->status->value === 'fully_allocated' && $tool->total_remaining > 0.01) {
            $warnings[] = 'Đã phân bổ hết nhưng vẫn còn giá trị còn lại.';
        }

        if (in_array($tool->status->value, ['disposed', 'broken', 'lost']) && $tool->total_remaining > 0.01) {
            $warnings[] = 'Đã xử lý nhưng còn giá trị chưa ghi nhận.';
        }

        if ($tool->recognition_method === 'allocation' && $tool->allocation_periods
            && $tool->periods_allocated < $tool->allocation_periods
            && in_array($tool->status->value, ['in_use'])) {
            $warnings[] = 'Phương pháp phân bổ nhưng không có lịch phân bổ.';
        }

        return $warnings;
    }
}
