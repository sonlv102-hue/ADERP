<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractPayment;
use App\Services\ProjectSubcontractPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectSubcontractPaymentController extends Controller
{
    public function __construct(private ProjectSubcontractPaymentService $service) {}

    public function store(Request $request, Project $project, ProjectSubcontract $subcontract): RedirectResponse
    {
        $this->authorize('projects.subcontracts.payment.create');
        abort_unless($subcontract->project_id === $project->id, 404);

        $data = $request->validate([
            'payment_date'    => ['required', 'date'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'payment_method'  => ['required', 'in:cash,bank'],
            'fund_id'         => ['nullable', 'exists:funds,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'pit_withholding_enabled' => ['nullable', 'boolean'],
            'pit_rate'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->create($subcontract, $data);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận thanh toán.');
    }

    public function cancel(Request $request, Project $project, ProjectSubcontract $subcontract, ProjectSubcontractPayment $payment): RedirectResponse
    {
        $this->authorize('projects.subcontracts.cancel');
        abort_unless($payment->subcontract_id === $subcontract->id && $subcontract->project_id === $project->id, 404);

        $reason = $request->input('cancel_reason', 'Hủy theo yêu cầu');

        try {
            $this->service->cancel($payment, $reason);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy thanh toán.');
    }
}
