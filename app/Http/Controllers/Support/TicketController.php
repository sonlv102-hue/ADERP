<?php

namespace App\Http\Controllers\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TicketController extends Controller
{
    public function __construct(private TicketService $service) {}

    public function index(Request $request): Response
    {
        $query = Ticket::with(['customer', 'assignee'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($q2) use ($q) {
                $q2->where('code', 'ilike', "%{$q}%")
                   ->orWhere('title', 'ilike', "%{$q}%");
            });
        }

        return Inertia::render('Support/Tickets/Index', [
            'tickets' => $query->paginate(20)->through(fn ($t) => [
                'id'             => $t->id,
                'code'           => $t->code,
                'title'          => $t->title,
                'customer'       => $t->customer->name,
                'assignee'       => $t->assignee?->name,
                'priority'       => $t->priority->value,
                'priority_label' => $t->priority->label(),
                'priority_color' => $t->priority->color(),
                'status'         => $t->status->value,
                'status_label'   => $t->status->label(),
                'status_color'   => $t->status->color(),
                'due_date'       => $t->due_date?->format('d/m/Y'),
                'created_at'     => $t->created_at->format('d/m/Y'),
            ]),
            'filters'    => $request->only(['status', 'priority', 'search']),
            'statuses'   => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Support/Tickets/Form', [
            'nextCode'   => Ticket::generateCode(),
            'customers'  => Customer::orderBy('name')->get(['id', 'name']),
            'users'      => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'orders'     => Order::orderByDesc('id')->get(['id', 'code']),
            'contracts'  => Contract::orderByDesc('id')->get(['id', 'code', 'title']),
            'statuses'   => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'        => ['required', 'string', 'max:20', 'unique:tickets,code'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['required', 'exists:customers,id'],
            'order_id'    => ['nullable', 'exists:orders,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority'    => ['required', 'string'],
            'category'    => ['nullable', 'string', 'max:50'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $ticket = Ticket::create([
            ...$data,
            'status'     => TicketStatus::Open,
            'created_by' => auth()->id(),
        ]);

        $technicals = User::role('technical')->get();
        \Illuminate\Support\Facades\Notification::send($technicals, new \App\Notifications\TicketCreatedNotification($ticket));

        return redirect()->route('support.tickets.show', $ticket)
            ->with('success', "Đã tạo ticket {$ticket->code}");
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load(['customer', 'order', 'contract', 'assignee', 'creator', 'logs.user']);

        return Inertia::render('Support/Tickets/Show', [
            'ticket' => [
                'id'             => $ticket->id,
                'code'           => $ticket->code,
                'title'          => $ticket->title,
                'description'    => $ticket->description,
                'customer'       => ['id' => $ticket->customer->id, 'name' => $ticket->customer->name],
                'order'          => $ticket->order ? ['id' => $ticket->order->id, 'code' => $ticket->order->code] : null,
                'contract'       => $ticket->contract ? ['id' => $ticket->contract->id, 'code' => $ticket->contract->code] : null,
                'assignee'       => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
                'priority'       => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'priority_color' => $ticket->priority->color(),
                'status'         => $ticket->status->value,
                'status_label'   => $ticket->status->label(),
                'status_color'   => $ticket->status->color(),
                'category'       => $ticket->category,
                'due_date'       => $ticket->due_date?->format('d/m/Y'),
                'resolved_at'    => $ticket->resolved_at?->format('d/m/Y H:i'),
                'closed_at'      => $ticket->closed_at?->format('d/m/Y H:i'),
                'creator'        => $ticket->creator->name,
                'created_at'     => $ticket->created_at->format('d/m/Y H:i'),
                'logs'           => $ticket->logs->map(fn ($l) => [
                    'id'         => $l->id,
                    'user'       => $l->user->name,
                    'action'     => $l->action,
                    'old_value'  => $l->old_value,
                    'new_value'  => $l->new_value,
                    'note'       => $l->note,
                    'created_at' => $l->created_at->format('d/m/Y H:i'),
                ]),
                'allowed_transitions' => $this->allowedTransitions($ticket),
            ],
            'allUsers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Ticket $ticket): Response
    {
        return Inertia::render('Support/Tickets/Form', [
            'ticket'     => $ticket,
            'nextCode'   => $ticket->code,
            'customers'  => Customer::orderBy('name')->get(['id', 'name']),
            'users'      => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'orders'     => Order::orderByDesc('id')->get(['id', 'code']),
            'contracts'  => Contract::orderByDesc('id')->get(['id', 'code', 'title']),
            'statuses'   => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()]),
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['required', 'exists:customers,id'],
            'order_id'    => ['nullable', 'exists:orders,id'],
            'contract_id' => ['nullable', 'exists:contracts,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority'    => ['required', 'string'],
            'category'    => ['nullable', 'string', 'max:50'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $ticket->update($data);

        return redirect()->route('support.tickets.show', $ticket)
            ->with('success', 'Đã cập nhật ticket.');
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->authorize('tickets.close');
        $ticket->delete();

        return redirect()->route('support.tickets.index')
            ->with('success', 'Đã xóa ticket.');
    }

    public function transition(Request $request, Ticket $ticket): RedirectResponse
    {
        $request->validate(['status' => ['required', 'string']]);
        $newStatus = TicketStatus::from($request->status);

        try {
            $this->service->transition($ticket, $newStatus);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Ticket chuyển sang: {$newStatus->label()}");
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('tickets.assign');
        $request->validate(['assigned_to' => ['nullable', 'exists:users,id']]);

        $this->service->assign($ticket, $request->assigned_to);

        return back()->with('success', 'Đã phân công ticket.');
    }

    public function addNote(Request $request, Ticket $ticket): RedirectResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->service->addNote($ticket, $request->note);

        return back()->with('success', 'Đã thêm ghi chú.');
    }

    private function allowedTransitions(Ticket $ticket): array
    {
        $map = [
            'open'        => [
                ['value' => 'in_progress', 'label' => 'Bắt đầu xử lý'],
            ],
            'in_progress' => [
                ['value' => 'resolved', 'label' => 'Đánh dấu đã giải quyết'],
                ['value' => 'open',     'label' => 'Trả về mở'],
            ],
            'resolved'    => [
                ['value' => 'closed', 'label' => 'Đóng ticket'],
                ['value' => 'open',   'label' => 'Mở lại'],
            ],
        ];

        return $map[$ticket->status->value] ?? [];
    }

    private function categories(): array
    {
        return [
            ['value' => 'hardware',  'label' => 'Phần cứng'],
            ['value' => 'software',  'label' => 'Phần mềm'],
            ['value' => 'network',   'label' => 'Mạng'],
            ['value' => 'other',     'label' => 'Khác'],
        ];
    }
}
