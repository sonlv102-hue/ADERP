<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\Supplier;
use App\Services\ProjectSubcontractService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectSubcontractController extends Controller
{
    public function __construct(private ProjectSubcontractService $service) {}

    private function validationRules(): array
    {
        return [
            'contractor_id'     => ['nullable', 'exists:suppliers,id'],
            'contractor_name'   => ['required', 'string', 'max:255'],
            'contractor_type'   => ['required', 'in:company,team,individual'],
            'contract_no'       => ['required', 'string', 'max:100'],
            'contract_date'     => ['required', 'date'],
            'scope_of_work'     => ['nullable', 'string'],
            'cost_group'        => ['required', 'in:subcontractor,labor,equipment,transport,other'],
            'amount_before_vat' => ['required', 'numeric', 'min:0'],
            'vat_rate'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'        => ['nullable', 'numeric', 'min:0'],
            'advance_rate'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'retention_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date'        => ['nullable', 'date'],
            'end_date'          => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function create(Project $project): Response
    {
        $this->authorize('projects.subcontracts.create');

        return Inertia::render('Projects/Subcontracts/Create', [
            'project'      => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'suppliers'    => Supplier::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('projects.subcontracts.create');

        $data = $request->validate($this->validationRules());
        $subcontract = $this->service->create($project, $data);

        return redirect()
            ->route('projects.projects.show', $project->id)
            ->with('success', "Đã tạo hợp đồng khoán {$subcontract->contract_no}.");
    }

    public function edit(Project $project, ProjectSubcontract $subcontract): Response
    {
        $this->authorize('projects.subcontracts.update');
        abort_unless($subcontract->project_id === $project->id, 404);

        return Inertia::render('Projects/Subcontracts/Edit', [
            'project'     => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'subcontract' => $subcontract,
            'suppliers'   => Supplier::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.update');
        abort_unless($subcontract->project_id === $project->id, 404);

        $data = $request->validate($this->validationRules());

        try {
            $this->service->update($subcontract, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('projects.projects.show', $project->id)->with('success', 'Đã cập nhật hợp đồng khoán.');
    }

    public function show(Project $project, ProjectSubcontract $subcontract): Response
    {
        $this->authorize('projects.subcontracts.view');
        abort_unless($subcontract->project_id === $project->id, 404);

        $subcontract->load(['contractor', 'creator', 'attachments.creator',
            'acceptances' => fn ($q) => $q->orderByDesc('acceptance_date'),
            'advances'    => fn ($q) => $q->orderByDesc('advance_date'),
            'payments'    => fn ($q) => $q->orderByDesc('payment_date'),
        ]);

        return Inertia::render('Projects/Subcontracts/Show', [
            'project'     => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'subcontract' => array_merge($subcontract->toArray(), [
                'status_label'   => $subcontract->status->label(),
                'status_color'   => $subcontract->status->color(),
                'accepted_total' => $subcontract->acceptedTotal(),
                'paid_total'     => $subcontract->paidTotal(),
                'amount_due'     => $subcontract->amountDue(),
            ]),
            'funds'        => Fund::where('type', 'cash')->orderBy('name')->get(['id', 'name', 'account_code']),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(['id', 'bank_name', 'account_number', 'account_code']),
        ]);
    }

    public function approve(Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.approve');
        abort_unless($subcontract->project_id === $project->id, 404);

        try {
            $this->service->approve($subcontract);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã duyệt hợp đồng khoán.');
    }

    public function close(Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.approve');
        abort_unless($subcontract->project_id === $project->id, 404);

        $subcontract->update(['status' => 'completed', 'updated_by' => auth()->id()]);

        return back()->with('success', 'Đã đóng hợp đồng khoán.');
    }

    public function cancel(Request $request, Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.cancel');
        abort_unless($subcontract->project_id === $project->id, 404);

        $reason = $request->input('cancel_reason', 'Hủy theo yêu cầu');

        try {
            $this->service->cancel($subcontract, $reason);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy hợp đồng khoán.');
    }
}
