<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ExpenseCategory;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Models\ProjectMaterial;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function index(): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::with(['customer', 'manager'])
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($p) => [
                    'id'                => $p->id,
                    'code'              => $p->code,
                    'name'              => $p->name,
                    'customer'          => $p->customer?->name ?? '—',
                    'manager'           => $p->manager?->name,
                    'start_date'        => $p->start_date?->format('d/m/Y'),
                    'expected_end_date' => $p->expected_end_date?->format('d/m/Y'),
                    'status'            => $p->status->value,
                    'status_label'      => $p->status->label(),
                    'status_color'      => $p->status->color(),
                    'budget'            => $p->budget,
                    'progress'          => $this->service->progressPercent($p),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Form', [
            'nextCode'  => Project::generateCode(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'contracts' => Contract::orderByDesc('id')->get(['id', 'code', 'title']),
            'users'     => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses'  => collect(ProjectStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'              => ['required', 'string', 'max:20', 'unique:projects,code'],
            'name'              => ['required', 'string', 'max:255'],
            'customer_id'       => ['required', 'exists:customers,id'],
            'contract_id'       => ['nullable', 'exists:contracts,id'],
            'location'          => ['nullable', 'string', 'max:255'],
            'manager_id'        => ['nullable', 'exists:users,id'],
            'start_date'        => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget'            => ['nullable', 'numeric', 'min:0'],
            'notes'             => ['nullable', 'string'],
        ]);

        $project = Project::create([
            ...$data,
            'status'     => ProjectStatus::Planning,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('projects.projects.show', $project)
            ->with('success', 'Đã tạo dự án ' . $project->code);
    }

    public function show(Project $project): Response
    {
        $project->load([
            'customer', 'contract', 'manager', 'creator',
            'tasks.assignee',
            'members.user',
            'materials.product',
            'expenses.creator',
        ]);

        return Inertia::render('Projects/Show', [
            'project' => [
                'id'                => $project->id,
                'code'              => $project->code,
                'name'              => $project->name,
                'customer'          => ['id' => $project->customer->id, 'name' => $project->customer->name],
                'contract'          => $project->contract ? ['id' => $project->contract->id, 'code' => $project->contract->code] : null,
                'location'          => $project->location,
                'manager'           => $project->manager ? ['id' => $project->manager->id, 'name' => $project->manager->name] : null,
                'start_date'        => $project->start_date?->format('d/m/Y'),
                'expected_end_date' => $project->expected_end_date?->format('d/m/Y'),
                'actual_end_date'   => $project->actual_end_date?->format('d/m/Y'),
                'budget'            => $project->budget,
                'status'            => $project->status->value,
                'status_label'      => $project->status->label(),
                'status_color'      => $project->status->color(),
                'notes'             => $project->notes,
                'creator'           => $project->creator->name,
                'created_at'        => $project->created_at->format('d/m/Y'),
                'progress'          => $this->service->progressPercent($project),
                'total_expenses'    => $project->totalExpenses(),
                'total_material_cost'=> $project->totalMaterialCost(),
                'tasks'             => $project->tasks->map(fn ($t) => [
                    'id'           => $t->id,
                    'title'        => $t->title,
                    'description'  => $t->description,
                    'assigned_to'  => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
                    'status'       => $t->status->value,
                    'status_label' => $t->status->label(),
                    'status_color' => $t->status->color(),
                    'priority'     => $t->priority,
                    'due_date'     => $t->due_date?->format('d/m/Y'),
                    'sort_order'   => $t->sort_order,
                ]),
                'members'           => $project->members->map(fn ($m) => [
                    'id'   => $m->id,
                    'user' => ['id' => $m->user->id, 'name' => $m->user->name],
                    'role' => $m->role,
                ]),
                'materials'         => $project->materials->map(fn ($m) => [
                    'id'           => $m->id,
                    'product'      => ['id' => $m->product->id, 'name' => $m->product->name, 'unit' => $m->product->unit],
                    'quantity'     => $m->quantity,
                    'unit_price'   => $m->unit_price,
                    'line_total'   => $m->lineTotal(),
                    'notes'        => $m->notes,
                    'stock_exit_id'=> $m->stock_exit_id,
                ]),
                'expenses'          => $project->expenses->map(fn ($e) => [
                    'id'           => $e->id,
                    'category'     => $e->category->value,
                    'category_label'=> $e->category->label(),
                    'description'  => $e->description,
                    'amount'       => $e->amount,
                    'expense_date' => $e->expense_date->format('d/m/Y'),
                    'creator'      => $e->creator?->name,
                ]),
                'allowed_transitions' => $this->allowedTransitions($project),
            ],
            'allUsers'    => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'allProducts' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'cost_price']),
            'expenseCategories' => collect(ExpenseCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('Projects/Form', [
            'project'   => $project,
            'nextCode'  => $project->code,
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'contracts' => Contract::orderByDesc('id')->get(['id', 'code', 'title']),
            'users'     => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses'  => collect(ProjectStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'customer_id'       => ['required', 'exists:customers,id'],
            'contract_id'       => ['nullable', 'exists:contracts,id'],
            'location'          => ['nullable', 'string', 'max:255'],
            'manager_id'        => ['nullable', 'exists:users,id'],
            'start_date'        => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date'],
            'budget'            => ['nullable', 'numeric', 'min:0'],
            'notes'             => ['nullable', 'string'],
        ]);

        $project->update($data);

        return redirect()->route('projects.projects.show', $project)
            ->with('success', 'Đã cập nhật dự án.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('projects.delete');
        $project->delete();

        return redirect()->route('projects.projects.index')
            ->with('success', 'Đã xóa dự án.');
    }

    public function transition(Request $request, Project $project): RedirectResponse
    {
        $request->validate(['status' => ['required', 'string']]);
        $newStatus = ProjectStatus::from($request->status);

        try {
            $this->service->transition($project, $newStatus);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Dự án chuyển sang: {$newStatus->label()}");
    }

    // Members
    public function addMember(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role'    => ['nullable', 'string', 'max:100'],
        ]);

        ProjectMember::firstOrCreate(
            ['project_id' => $project->id, 'user_id' => $data['user_id']],
            ['role' => $data['role'] ?? null]
        );

        return back()->with('success', 'Đã thêm thành viên.');
    }

    public function removeMember(Project $project, ProjectMember $member): RedirectResponse
    {
        abort_unless($member->project_id === $project->id, 404);
        $member->delete();

        return back()->with('success', 'Đã xóa thành viên.');
    }

    // Materials
    public function addMaterial(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity'   => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'notes'      => ['nullable', 'string'],
        ]);

        $project->materials()->create($data);

        return back()->with('success', 'Đã thêm vật tư.');
    }

    public function removeMaterial(Project $project, ProjectMaterial $material): RedirectResponse
    {
        abort_unless($material->project_id === $project->id, 404);
        $material->delete();

        return back()->with('success', 'Đã xóa vật tư.');
    }

    // Expenses
    public function addExpense(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'category'     => ['required', 'string'],
            'description'  => ['required', 'string', 'max:255'],
            'amount'       => ['required', 'numeric', 'min:0'],
            'expense_date' => ['required', 'date'],
        ]);

        $project->expenses()->create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Đã ghi nhận chi phí.');
    }

    public function removeExpense(Project $project, ProjectExpense $expense): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);
        $expense->delete();

        return back()->with('success', 'Đã xóa chi phí.');
    }

    private function allowedTransitions(Project $project): array
    {
        $map = [
            'planning'    => [
                ['value' => 'in_progress', 'label' => 'Bắt đầu thi công'],
                ['value' => 'cancelled',   'label' => 'Hủy dự án'],
            ],
            'in_progress' => [
                ['value' => 'on_hold',    'label' => 'Tạm dừng'],
                ['value' => 'completed',  'label' => 'Hoàn thành'],
                ['value' => 'cancelled',  'label' => 'Hủy dự án'],
            ],
            'on_hold'     => [
                ['value' => 'in_progress', 'label' => 'Tiếp tục'],
                ['value' => 'cancelled',   'label' => 'Hủy dự án'],
            ],
        ];

        return $map[$project->status->value] ?? [];
    }
}
