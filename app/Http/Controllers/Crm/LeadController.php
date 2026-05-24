<?php

namespace App\Http\Controllers\Crm;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(private LeadService $service) {}

    public function index(Request $request): Response
    {
        $query = Lead::with(['assignedTo', 'creator'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Crm/Leads/Index', [
            'leads' => $query->paginate(20)->through(fn ($l) => [
                'id'                => $l->id,
                'code'              => $l->code,
                'full_name'         => $l->full_name,
                'company_name'      => $l->company_name,
                'phone'             => $l->phone,
                'email'             => $l->email,
                'source'            => $l->source,
                'status'            => $l->status->value,
                'status_label'      => $l->status->label(),
                'status_color'      => $l->status->color(),
                'next_follow_up'    => $l->next_follow_up?->format('Y-m-d'),
                'expected_value'    => $l->expected_value,
                'assigned_to_name'  => $l->assignedTo?->name,
            ]),
            'statuses'     => collect(LeadStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Crm/Leads/Form', [
            'nextCode'   => Lead::generateCode(),
            'statuses'   => collect(LeadStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'sales_users'=> User::role(['admin', 'sales', 'director'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name'      => ['required', 'string', 'max:150'],
            'company_name'   => ['nullable', 'string', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:100'],
            'source'         => ['nullable', 'string', 'max:50'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'status'         => ['required', Rule::enum(LeadStatus::class)],
            'next_follow_up' => ['nullable', 'date'],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
        ]);

        $data['code']       = Lead::generateCode();
        $data['created_by'] = $request->user()->id;

        Lead::create($data);

        return redirect()->route('crm.leads.index')
            ->with('success', 'Đã tạo lead mới.');
    }

    public function show(Lead $lead): Response
    {
        $lead->load(['assignedTo', 'creator', 'convertedCustomer']);

        return Inertia::render('Crm/Leads/Show', [
            'lead' => [
                'id'                     => $lead->id,
                'code'                   => $lead->code,
                'full_name'              => $lead->full_name,
                'company_name'           => $lead->company_name,
                'phone'                  => $lead->phone,
                'email'                  => $lead->email,
                'source'                 => $lead->source,
                'status'                 => $lead->status->value,
                'status_label'           => $lead->status->label(),
                'status_color'           => $lead->status->color(),
                'next_follow_up'         => $lead->next_follow_up?->format('Y-m-d'),
                'expected_value'         => $lead->expected_value,
                'notes'                  => $lead->notes,
                'assigned_to_name'       => $lead->assignedTo?->name,
                'creator_name'           => $lead->creator?->name,
                'converted_customer_id'  => $lead->converted_customer_id,
                'converted_customer_code'=> $lead->convertedCustomer?->code,
                'created_at'             => $lead->created_at->format('d/m/Y H:i'),
            ],
        ]);
    }

    public function edit(Lead $lead): Response
    {
        return Inertia::render('Crm/Leads/Form', [
            'lead'       => [
                'id'             => $lead->id,
                'code'           => $lead->code,
                'full_name'      => $lead->full_name,
                'company_name'   => $lead->company_name,
                'phone'          => $lead->phone,
                'email'          => $lead->email,
                'source'         => $lead->source,
                'assigned_to'    => $lead->assigned_to,
                'status'         => $lead->status->value,
                'next_follow_up' => $lead->next_follow_up?->format('Y-m-d'),
                'expected_value' => $lead->expected_value,
                'notes'          => $lead->notes,
            ],
            'statuses'    => collect(LeadStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'sales_users' => User::role(['admin', 'sales', 'director'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $data = $request->validate([
            'full_name'      => ['required', 'string', 'max:150'],
            'company_name'   => ['nullable', 'string', 'max:150'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:100'],
            'source'         => ['nullable', 'string', 'max:50'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'status'         => ['required', Rule::enum(LeadStatus::class)],
            'next_follow_up' => ['nullable', 'date'],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
        ]);

        $lead->update($data);

        return redirect()->route('crm.leads.show', $lead)
            ->with('success', 'Đã cập nhật lead.');
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        $allowed = [LeadStatus::New->value, LeadStatus::Lost->value];

        if (! in_array($lead->status->value, $allowed, true)) {
            return back()->with('error', 'Chỉ được xóa lead ở trạng thái Mới hoặc Thất bại.');
        }

        $lead->delete();

        return redirect()->route('crm.leads.index')
            ->with('success', 'Đã xóa lead.');
    }

    public function convert(Request $request, Lead $lead): RedirectResponse
    {
        if ($lead->status === LeadStatus::Won) {
            return back()->with('error', 'Lead này đã được chuyển đổi thành khách hàng.');
        }

        $data = $request->validate([
            'name'    => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:20'],
            'email'   => ['nullable', 'email'],
        ]);

        $customer = $this->service->convertToCustomer($lead, $data);

        return redirect()->route('crm.customers.show', $customer)
            ->with('success', 'Đã chuyển đổi lead thành khách hàng.');
    }
}
