<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SmallTool;
use App\Models\SmallToolIssue;
use App\Models\SmallToolIssueItem;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolIssueController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('ccdc.view');

        $issues = SmallToolIssue::with('responsibleEmployee')
            ->when($request->search, fn ($q, $s) => $q->where('code', 'ilike', "%{$s}%"))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn ($r) => [
                'id'               => $r->id,
                'code'             => $r->code,
                'issue_date'       => $r->issue_date->format('Y-m-d'),
                'department'       => $r->department,
                'employee_name'    => $r->responsibleEmployee?->name,
                'recognition_method' => $r->recognition_method,
                'total_amount'     => (float) $r->total_amount,
                'status'           => $r->status,
            ]);

        return Inertia::render('Accounting/SmallTools/Issues/Index', [
            'issues'  => $issues,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('ccdc.manage');

        return Inertia::render('Accounting/SmallTools/Issues/Form', [
            'nextCode'    => SmallToolIssue::generateCode(),
            'inStockTools' => SmallTool::where('status', 'in_stock')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'unit', 'quantity', 'original_cost', 'total_cost']),
            'employees'   => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'projects'    => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'issue_date'              => 'required|date',
            'department'              => 'nullable|string|max:100',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'project_id'              => 'nullable|exists:projects,id',
            'recognition_method'      => 'required|in:immediate,allocation',
            'allocation_periods'      => 'nullable|integer|min:1',
            'allocation_start_date'   => 'nullable|date',
            'expense_account_code'    => 'required|string|max:20',
            'notes'                   => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.small_tool_id'   => 'required|exists:small_tools,id',
            'items.*.amount'          => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $request) {
            $issue = SmallToolIssue::create([
                'code'                    => SmallToolIssue::generateCode(),
                'issue_date'              => $data['issue_date'],
                'department'              => $data['department'] ?? null,
                'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                'project_id'              => $data['project_id'] ?? null,
                'recognition_method'      => $data['recognition_method'],
                'allocation_periods'      => $data['allocation_periods'] ?? null,
                'allocation_start_date'   => $data['allocation_start_date'] ?? null,
                'expense_account_code'    => $data['expense_account_code'],
                'notes'                   => $data['notes'] ?? null,
                'status'                  => 'draft',
                'created_by'              => auth()->id(),
            ]);

            foreach ($data['items'] as $item) {
                SmallToolIssueItem::create([
                    'small_tool_issue_id' => $issue->id,
                    'small_tool_id'       => $item['small_tool_id'],
                    'quantity'            => 1,
                    'amount'              => $item['amount'],
                ]);
            }

            if ($request->auto_confirm) {
                $this->service->confirmIssue($issue->fresh('items.tool'));
            }
        });

        return redirect()->route('accounting.small-tool-issues.index')
            ->with('success', 'Đã tạo phiếu xuất dùng CCDC.');
    }

    public function confirm(SmallToolIssue $issue): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        try {
            $this->service->confirmIssue($issue->load('items.tool'));
            return back()->with('success', 'Đã xác nhận phiếu xuất dùng CCDC.');
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function cancel(SmallToolIssue $issue): RedirectResponse
    {
        $this->authorize('ccdc.cancel');

        try {
            $this->service->recallIssue($issue->load('items.tool'));
            return back()->with('success', 'Đã thu hồi phiếu xuất dùng.');
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
