<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\SmallTool;
use App\Models\SmallToolTransfer;
use App\Models\Warehouse;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolTransferController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    public function create(SmallTool $tool): Response
    {
        $this->authorize('ccdc.manage');

        return Inertia::render('Accounting/SmallTools/Transfers/Form', [
            'tool'      => [
                'id'              => $tool->id,
                'code'            => $tool->code,
                'name'            => $tool->name,
                'status'          => $tool->status->value,
                'department'      => $tool->department,
                'expense_account_code' => $tool->expense_account_code,
                'responsible_employee_id' => $tool->responsible_employee_id,
                'project_id'      => $tool->project_id,
                'warehouse_id'    => $tool->warehouse_id,
            ],
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'projects'  => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'transfer_date'           => 'required|date',
            'to_department'           => 'nullable|string|max:100',
            'to_employee_id'          => 'nullable|exists:employees,id',
            'to_project_id'           => 'nullable|exists:projects,id',
            'to_warehouse_id'         => 'nullable|exists:warehouses,id',
            'new_expense_account_code' => 'nullable|string|max:20',
            'reason'                  => 'nullable|string',
            'notes'                   => 'nullable|string',
        ]);

        $data['from_department']  = $tool->department;
        $data['from_employee_id'] = $tool->responsible_employee_id;
        $data['from_project_id']  = $tool->project_id;
        $data['from_warehouse_id'] = $tool->warehouse_id;

        try {
            $this->service->createTransfer($tool, $data);
            return redirect()->route('accounting.small-tools.show', $tool)
                ->with('success', 'Đã tạo phiếu điều chuyển CCDC.');
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
