<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SmallToolStatus;
use App\Http\Controllers\Controller;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    // Bảng phân bổ CCDC — lịch phân bổ từng CCDC qua các kỳ
    public function allocationSchedule(Request $request): Response
    {
        $this->authorize('reports.view');

        $toolFilter   = $request->input('tool');
        $statusFilter = $request->input('allocation_status');

        $schedule = SmallTool::with(['allocations' => fn ($q) => $q->orderBy('period')])
            ->whereHas('allocations')
            ->when($toolFilter, fn ($q) => $q->where(function ($sq) use ($toolFilter) {
                $sq->where('code', 'ilike', "%{$toolFilter}%")
                   ->orWhere('name', 'ilike', "%{$toolFilter}%");
            }))
            ->when($statusFilter, fn ($q) => $q->where('allocation_status', $statusFilter))
            ->orderBy('code')
            ->get()
            ->map(fn (SmallTool $t) => [
                'id'                   => $t->id,
                'code'                 => $t->code,
                'name'                 => $t->name,
                'department'           => $t->department,
                'original_cost'        => (float) $t->original_cost,
                'expense_account_code' => $t->expense_account_code,
                'allocation_status'    => $t->allocation_status,
                'allocations'          => $t->allocations->map(fn ($a) => [
                    'id'               => $a->id,
                    'period'           => $a->period,
                    'amount'           => (float) $a->amount,
                    'accumulated'      => (float) $a->accumulated_before + (float) $a->amount,
                    'remaining'        => (float) $a->remaining_after,
                    'status'           => $a->status,
                    'journal_entry_id' => $a->journal_entry_id,
                ])->values(),
            ]);

        return Inertia::render('Accounting/SmallTools/Reports/AllocationSchedule', [
            'schedule'      => $schedule->values(),
            'currentPeriod' => now()->format('Y-m'),
            'filters'       => ['tool' => $toolFilter, 'allocation_status' => $statusFilter],
        ]);
    }

    // Đối soát GL
    public function glReconcile(Request $request): Response
    {
        $this->authorize('reports.view');

        $asOf = $request->input('as_of', now()->toDateString());

        $glBalance = function (string $account) use ($asOf): float {
            return (float) (DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.account_code', $account)
                ->where('journal_entries.status', 'posted')
                ->where('journal_entries.entry_date', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
                ->value('balance') ?? 0);
        };

        $gl1531 = $glBalance('1531');
        $gl2422 = $glBalance('2422');

        $tools = SmallTool::with('allocations')->get()->map(fn (SmallTool $t) => [
            'id'             => $t->id,
            'code'           => $t->code,
            'name'           => $t->name,
            'original_cost'  => (float) $t->original_cost,
            'total_allocated' => (float) $t->total_allocated,
            'total_remaining' => (float) $t->total_remaining,
            'status'         => $t->status->value,
            'warnings'       => $this->detectWarnings($t),
        ]);

        $inStockTotal       = $tools->where('status', 'in_stock')->sum('original_cost');
        $allocatingRemaining = $tools->where('status', 'allocating')->sum('total_remaining');

        $warnings = $tools->flatMap(fn ($t) => collect($t['warnings'])->map(fn ($msg) => [
            'code'    => $t['code'],
            'name'    => $t['name'],
            'message' => $msg,
        ]))->values()->all();

        return Inertia::render('Accounting/SmallTools/Reports/GlReconcile', [
            'asOf'      => $asOf,
            'reconcile' => [
                'gl_1531'              => $gl1531,
                'in_stock_total'       => $inStockTotal,
                'diff_1531'            => $gl1531 - $inStockTotal,
                'gl_2422'              => $gl2422,
                'allocating_remaining' => $allocatingRemaining,
                'diff_2422'            => $gl2422 - $allocatingRemaining,
                'warnings'             => $warnings,
                'tools'                => $tools->values()->all(),
            ],
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
