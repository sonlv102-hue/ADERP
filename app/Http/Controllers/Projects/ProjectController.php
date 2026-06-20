<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ExpenseCategory;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Models\ProjectExpense;
use App\Models\ProjectMaterial;
use App\Models\ProjectMember;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockExit;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectWipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private ProjectService    $service,
        private ProjectWipService $wip,
    ) {}

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
            'members.employee',
            'materials.product',
            'expenses.creator',
            'expenses.supplier',
        ]);

        // Phiếu xuất kho thực tế cho dự án (confirmed + cancelled để truy vết)
        $stockExits = StockExit::with(['warehouse', 'items.product'])
            ->where('project_id', $project->id)
            ->where('issue_purpose', 'project_cost')
            ->orderByDesc('exit_date')
            ->get();

        $wipByExitId = ProjectWipEntry::where('project_id', $project->id)
            ->where('source_type', StockExit::class)
            ->with('journalEntry')
            ->get()
            ->keyBy('source_id');

        $wipByExpenseId = ProjectWipEntry::where('project_id', $project->id)
            ->where('source_type', ProjectExpense::class)
            ->with('journalEntry')
            ->get()
            ->keyBy('source_id');

        $stockExitItems = $stockExits->flatMap(fn ($exit) =>
            $exit->items->map(fn ($item) => [
                'exit_id'      => $exit->id,
                'exit_code'    => $exit->code,
                'exit_date'    => $exit->exit_date?->format('d/m/Y'),
                'warehouse'    => $exit->warehouse->name,
                'product_code' => $item->product->code,
                'product_name' => $item->product->name,
                'quantity'     => (float) $item->quantity,
                'unit'         => $item->product->unit,
                'unit_cost'    => (float) ($item->source_cost ?? $item->unit_price ?? 0),
                'total_cost'   => (float) ($item->total_cost ?? ($item->quantity * ($item->source_cost ?? $item->unit_price ?? 0))),
                'journal_code' => $wipByExitId->get($exit->id)?->journalEntry?->code ?? '—',
                'status'       => $exit->status->value,
                'status_label' => $exit->status->label(),
                'is_cancelled' => $exit->status->value === 'cancelled',
            ])
        )->values();

        // Vật tư phát sinh trực tiếp
        $directMaterials = $project->directMaterials()
            ->with(['product', 'journalEntry', 'creator', 'purchaseInvoiceItem'])
            ->orderByDesc('occurrence_date')
            ->get()
            ->map(fn ($m) => [
                'id'             => $m->id,
                'product_name'   => $m->product?->name ?? $m->product_name ?? '—',
                'product_code'   => $m->product?->code,
                'quantity'       => (float) $m->quantity,
                'unit_price'     => (float) $m->unit_price,
                'total_amount'   => (float) $m->total_amount,
                'occurrence_date'=> $m->occurrence_date->format('d/m/Y'),
                'handling_type'  => $m->handling_type->value,
                'handling_label' => $m->handling_type->label(),
                'handling_color' => $m->handling_type->color(),
                'status'         => $m->status,
                'journal_code'   => $m->journalEntry?->code,
                'pi_item_ref'    => $m->purchaseInvoiceItem ? "Dòng HĐ #{$m->purchase_invoice_item_id}" : null,
                'notes'          => $m->notes,
                'source_ref'     => $m->source_document_ref,
                'creator'        => $m->creator?->name,
                'cancel_reason'  => $m->cancel_reason,
            ]);

        $stockExitTotal    = $stockExitItems->where('is_cancelled', false)->sum('total_cost');
        $directMaterialTotal = $directMaterials->where('status', 'active')
            ->whereIn('handling_type', ['invoice_link', 'journal_entry', 'tracking_only'])
            ->sum('total_amount');

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
                    'id'       => $m->id,
                    'employee' => [
                        'id'         => $m->employee->id,
                        'code'       => $m->employee->code,
                        'name'       => $m->employee->name,
                        'position'   => $m->employee->position,
                        'department' => $m->employee->department,
                    ],
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
                    'id'             => $e->id,
                    'category'       => $e->category->value,
                    'category_label' => $e->category->label(),
                    'description'    => $e->description,
                    'amount'         => $e->amount,
                    'expense_date'   => $e->expense_date->format('d/m/Y'),
                    'creator'        => $e->creator?->name,
                    'supplier_id'    => $e->supplier_id,
                    'supplier_name'  => $e->supplier?->name,
                    'invoice_number' => $e->invoice_number,
                    'payment_method' => $e->payment_method ?? 'payable',
                    'vat_amount'     => $e->vat_amount ?? 0,
                    'debit_account'  => $e->debit_account,
                    'je_code'        => $wipByExpenseId->get($e->id)?->journalEntry?->code,
                    'je_id'          => $wipByExpenseId->get($e->id)?->journal_entry_id,
                ]),
                'allowed_transitions' => $this->allowedTransitions($project),
            ],
            'allActiveProjects' => Project::whereNotIn('status', ['cancelled', 'completed'])
                ->where('id', '!=', $project->id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'allEmployees' => Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get(['id', 'code', 'name', 'position', 'department']),
            'allProducts' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'cost_price']),
            'expenseCategories' => collect(ExpenseCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'wipSummary'     => $this->wip->getWipSummary($project->id),
            'wipEntries'     => $this->wip->getWipEntries($project->id),
            'wipTotal'       => (int) \App\Models\ProjectWipEntry::where('project_id', $project->id)->where('status', 'active')->sum('amount'),
            'purchaseOrders' => PurchaseOrder::with(['supplier'])
                ->where('project_id', $project->id)
                ->orderByDesc('id')
                ->get()
                ->map(fn ($po) => [
                    'id'          => $po->id,
                    'code'        => $po->code,
                    'supplier'    => $po->supplier->name,
                    'order_date'  => $po->order_date->format('d/m/Y'),
                    'status'      => $po->status->value,
                    'status_label'=> $po->status->label(),
                    'total'       => (float) $po->items()->sum(\DB::raw('quantity * unit_price')),
                ]),
            'stockExitItems'       => $stockExitItems,
            'stockExitTotal'       => (float) $stockExitTotal,
            'directMaterials'      => $directMaterials,
            'directMaterialTotal'  => (float) $directMaterialTotal,
            // Budget (hợp đồng khách hàng) vs Actual (hóa đơn mua hàng qua PO dự án)
            'contract_value'  => $project->contract ? (float) $project->contract->value : null,
            'actual_cost_from_pi' => (float) \DB::table('purchase_invoices as pi')
                ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
                ->where('po.project_id', $project->id)
                ->whereIn('pi.status', ['valid', 'partial_paid', 'paid'])
                ->sum('pi.total'),
            'purchaseInvoices' => PurchaseInvoice::with(['supplier', 'purchaseOrder'])
                ->whereHas('purchaseOrder', fn ($q) => $q->where('project_id', $project->id))
                ->orderByDesc('id')
                ->get()
                ->map(fn ($pi) => [
                    'id'           => $pi->id,
                    'code'         => $pi->code,
                    'supplier'     => $pi->supplier->name,
                    'po_code'      => $pi->purchaseOrder?->code,
                    'invoice_date' => $pi->invoice_date?->format('d/m/Y'),
                    'total'        => (float) $pi->total,
                    'status'       => $pi->status->value,
                    'status_label' => $pi->status->label(),
                    'status_color' => $pi->status->color(),
                ]),
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

        if ($project->status !== ProjectStatus::Cancelled) {
            return back()->with('error', 'Chỉ có thể xóa dự án đã hủy.');
        }

        DB::transaction(function () use ($project) {
            // Null out project_id on linked POs/stock exits to preserve financial records
            DB::table('purchase_orders')->where('project_id', $project->id)->update(['project_id' => null]);
            DB::table('stock_exits')->where('project_id', $project->id)->update(['project_id' => null]);

            DB::table('project_wip_entries')->where('project_id', $project->id)->delete();
            $project->tasks()->delete();
            $project->members()->delete();
            $project->materials()->delete();
            $project->expenses()->delete();
            $project->delete();
        });

        return redirect()->route('projects.projects.index')
            ->with('success', 'Đã xóa dự án ' . $project->code . '.');
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
            'employee_id' => ['required', 'exists:employees,id'],
            'role'        => ['nullable', 'string', 'max:100'],
        ]);

        ProjectMember::firstOrCreate(
            ['project_id' => $project->id, 'employee_id' => $data['employee_id']],
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
            'category'            => ['required', 'string'],
            'description'         => ['required', 'string', 'max:255'],
            'amount'              => ['required', 'numeric', 'min:0'],
            'expense_date'        => ['required', 'date'],
            'supplier_id'         => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchase_invoice_id' => ['nullable', 'integer'],
            'invoice_number'      => ['nullable', 'string', 'max:100'],
            'payment_method'      => ['nullable', 'string', 'in:cash,bank,payable'],
            'vat_rate'            => ['nullable', 'numeric', 'min:0'],
            'vat_amount'          => ['nullable', 'integer', 'min:0'],
            'debit_account'       => ['nullable', 'string', 'max:20'],
            'credit_account'      => ['nullable', 'string', 'max:20'],
        ]);

        try {
            DB::transaction(function () use ($data, $project) {
                $expense = $project->expenses()->create([
                    ...$data,
                    'created_by' => auth()->id(),
                ]);

                if ((float) $expense->amount > 0) {
                    $expense->loadMissing('project');
                    $this->wip->createFromExpense($expense);
                }
            });
        } catch (\Throwable $e) {
            \Log::error("addExpense failed project#{$project->id}: {$e->getMessage()}");
            return back()->with('error', 'Không thể ghi nhận chi phí: ' . $e->getMessage())->withInput();
        }

        return back()->with('success', 'Đã ghi nhận chi phí.');
    }

    public function removeExpense(Project $project, ProjectExpense $expense): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);
        $expense->delete();

        return back()->with('success', 'Đã xóa chi phí.');
    }

    public function recognizeCost(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        try {
            $this->wip->recognizeCost($project, $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã kết chuyển chi phí dự án {$project->code} vào giá vốn.");
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
