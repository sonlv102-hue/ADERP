<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractAcceptance;
use App\Services\ProjectSubcontractAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectSubcontractAcceptanceController extends Controller
{
    public function __construct(private ProjectSubcontractAcceptanceService $service) {}

    public function store(Request $request, Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        // Nghiệm thu = tạo + ghi sổ trong 1 bước → yêu cầu quyền post (cao nhất trong nhóm acceptance)
        $this->authorize('projects.subcontracts.acceptance.post');
        abort_unless($subcontract->project_id === $project->id, 404);

        $data = $request->validate([
            'acceptance_no'     => ['nullable', 'string', 'max:100'],
            'acceptance_date'   => ['required', 'date'],
            'description'       => ['nullable', 'string', 'max:500'],
            'amount_before_vat' => ['required', 'numeric', 'min:0.01'],
            'vat_rate'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'        => ['nullable', 'numeric', 'min:0'],
            'invoice_no'        => ['nullable', 'string', 'max:100'],
            'invoice_date'      => ['nullable', 'date'],
        ]);

        try {
            $this->service->post($subcontract, $data);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận nghiệm thu.');
    }

    public function cancel(Request $request, Project $project, ProjectSubcontract $subcontract, ProjectSubcontractAcceptance $acceptance): RedirectResponse
    {
        $this->authorize('projects.subcontracts.cancel');
        abort_unless($acceptance->subcontract_id === $subcontract->id && $subcontract->project_id === $project->id, 404);

        $reason = $request->input('cancel_reason', 'Hủy theo yêu cầu');

        try {
            $this->service->cancel($acceptance, $reason);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy nghiệm thu.');
    }
}
