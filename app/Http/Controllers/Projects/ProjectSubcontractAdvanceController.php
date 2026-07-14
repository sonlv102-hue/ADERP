<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractAdvance;
use App\Services\ProjectSubcontractAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectSubcontractAdvanceController extends Controller
{
    public function __construct(private ProjectSubcontractAdvanceService $service) {}

    public function store(Request $request, Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.update');
        abort_unless($subcontract->project_id === $project->id, 404);

        $data = $request->validate([
            'advance_date'    => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'payment_method'  => ['required', 'in:cash,bank'],
            'fund_id'         => ['nullable', 'exists:funds,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->create($subcontract, $data);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận tạm ứng.');
    }

    public function cancel(Request $request, Project $project, ProjectSubcontract $subcontract, ProjectSubcontractAdvance $advance): RedirectResponse
    {
        $this->authorize('projects.subcontracts.cancel');
        abort_unless($advance->subcontract_id === $subcontract->id && $subcontract->project_id === $project->id, 404);

        $reason = $request->input('cancel_reason', 'Hủy theo yêu cầu');

        try {
            $this->service->cancel($advance, $reason);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy tạm ứng.');
    }
}
