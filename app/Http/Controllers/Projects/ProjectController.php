<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ExpenseCategory;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectExpense;
use App\Models\ProjectMaterial;
use App\Models\ProjectMember;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockExit;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\AccountingSettings;
use App\Services\ProjectExtraCostTransferService;
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
        private ProjectService                   $service,
        private ProjectWipService                $wip,
        private AccountingService                $accounting,
        private ProjectExtraCostTransferService  $transferService,
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
            'expenses.employee',
            'expenses.fund',
            'expenses.bankAccount',
            'expenses.cashVoucher',
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

        // Kết chuyển 154 theo từng chi phí PS
        $transfersByExpenseId = ProjectExtraCostTransfer::where('project_id', $project->id)
            ->with('journalEntry')
            ->orderBy('transfer_date')
            ->get()
            ->groupBy('project_expense_id');

        // JE gốc của chi phí PS (cho các chi phí non-154, không tạo WIP trực tiếp)
        $expenseIds = $project->expenses->pluck('id');
        $jeByExpenseId = JournalEntry::where('reference_type', ProjectExpense::class)
            ->whereIn('reference_id', $expenseIds)
            ->whereIn('status', ['draft', 'posted'])
            ->pluck('id', 'reference_id');

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
                'expenses'          => $project->expenses->map(function ($e) use ($wipByExpenseId, $transfersByExpenseId, $jeByExpenseId) {
                    $debitAcct        = $e->debit_account;
                    $isDirectTo154    = $debitAcct && str_starts_with($debitAcct, '154');
                    $isCancelled      = ($e->status ?? 'posted') === 'cancelled';
                    $directWip        = $wipByExpenseId->get($e->id);
                    $transfers        = $transfersByExpenseId->get($e->id, collect());
                    $postedTransfers  = $transfers->where('status', 'posted');
                    $transferredAmt   = (int) $postedTransfers->sum('amount');
                    $expenseAmt       = (int) round((float) $e->amount);
                    $remainingAmt     = max(0, $expenseAmt - $transferredAmt);

                    // JE gốc: ưu tiên từ WIP entry, fallback về query JE trực tiếp
                    $jeId   = $directWip?->journal_entry_id ?? $jeByExpenseId->get($e->id);
                    $jeCode = $directWip?->journalEntry?->code
                        ?? ($jeId ? \App\Models\JournalEntry::find($jeId)?->code : null);

                    // WIP "legacy": expense TK 154, có WIP entry, chưa có transfer record.
                    $isLegacyWip = $isDirectTo154 && (bool) $directWip && $transfers->isEmpty();

                    // Phát hiện lỗi dữ liệu WIP:
                    // - TK 154 nhưng không có WIP entry và expense đã posted
                    // - Không phải TK 154 nhưng có WIP entry (code cũ tạo nhầm)
                    $isWipError = !$isCancelled && (
                        ($isDirectTo154 && !$directWip && $jeId !== null)
                        || (!$isDirectTo154 && (bool) $directWip && $postedTransfers->isEmpty())
                    );

                    // JE thực tế: gộp cả direct FK và computed (từ WIP/reference)
                    $effectiveJeId = $e->journal_entry_id ?? $jeId;

                    // Trạng thái kết chuyển
                    $transferStatus = match (true) {
                        $isCancelled                                     => 'cancelled',
                        $effectiveJeId === null                          => 'not_posted',
                        $isWipError                                      => 'data_error',
                        $isDirectTo154                                   => 'direct_154',
                        $isLegacyWip                                     => 'legacy',
                        $transferredAmt === 0                            => 'none',
                        $transferredAmt >= $expenseAmt                   => 'full',
                        default                                          => 'partial',
                    };

                    // Có thể kết chuyển nếu: có JE, TK Nợ không phải 154, không phải legacy/cancelled, còn tiền
                    $canTransfer = !$isCancelled
                        && !$isDirectTo154
                        && !$isLegacyWip
                        && $effectiveJeId !== null
                        && $remainingAmt > 0;

                    return [
                        'id'                  => $e->id,
                        'category'            => $e->category->value,
                        'category_label'      => $e->category->label(),
                        'description'         => $e->description,
                        'amount'              => $expenseAmt,
                        'vat_amount'          => $e->vat_amount ?? 0,
                        'expense_date'        => $e->expense_date->format('d/m/Y'),
                        'creator'             => $e->creator?->name,
                        'status'              => $e->status ?? 'posted',
                        'supplier_id'         => $e->supplier_id,
                        'supplier_name'       => $e->supplier?->name,
                        'employee_id'         => $e->employee_id,
                        'employee_name'       => $e->employee?->name,
                        'fund_id'             => $e->fund_id,
                        'fund_name'           => $e->fund?->name,
                        'bank_account_id'     => $e->bank_account_id,
                        'bank_account_name'   => $e->bankAccount ? ($e->bankAccount->bank_name . ' - ' . $e->bankAccount->account_number) : null,
                        'invoice_number'      => $e->invoice_number,
                        'payment_method'          => $e->payment_method ?? 'payable',
                        'labor_type'              => $e->labor_type?->value,
                        'pit_withholding_enabled' => (bool) ($e->pit_withholding_enabled ?? false),
                        'pit_rate'                => $e->pit_rate,
                        'pit_amount'              => $e->pit_amount ?? 0,
                        'net_payment_amount'      => $e->net_payment_amount ?? 0,
                        'vat_rate'                => $e->vat_rate,
                        'debit_account'           => $debitAcct,
                        'credit_account'          => $e->credit_account,
                        'je_code'             => $e->journal_entry_id ? (\App\Models\JournalEntry::find($e->journal_entry_id)?->code ?? $jeCode) : $jeCode,
                        'je_id'               => $e->journal_entry_id ?? $jeId,
                        'cash_voucher_id'     => $e->cash_voucher_id,
                        'cash_voucher_code'   => $e->cashVoucher?->code,
                        // Kết chuyển 154
                        'transfer_status'     => $transferStatus,
                        'transferred_amount'  => $transferredAmt,
                        'remaining_amount'    => $remainingAmt,
                        'can_transfer'        => $canTransfer,
                        'transfers'           => $postedTransfers->values()->map(fn ($t) => [
                            'id'              => $t->id,
                            'transfer_date'   => $t->transfer_date->format('d/m/Y'),
                            'amount'          => $t->amount,
                            'debit_account'   => $t->debit_account,
                            'credit_account'  => $t->credit_account,
                            'description'     => $t->description,
                            'status'          => $t->status,
                            'je_code'         => $t->journalEntry?->code,
                            'je_id'           => $t->journal_entry_id,
                        ])->toArray(),
                    ];
                }),
                'allowed_transitions' => $this->allowedTransitions($project),
            ],
            'allActiveProjects' => Project::whereNotIn('status', ['cancelled', 'completed'])
                ->where('id', '!=', $project->id)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'allEmployees' => Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get(['id', 'code', 'name', 'position', 'department']),
            'allProducts' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'cost_price']),
            'expenseCategories' => collect(ExpenseCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'funds' => Fund::orderBy('name')->get(['id', 'name', 'account_code', 'type']),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(['id', 'bank_name', 'account_number', 'account_code']),
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
        $creditAccount = $request->input('credit_account', '');
        $laborType     = $request->input('labor_type', '');

        // supplier_id required when: credit_account = 3311 OR labor_type = subcontractor_invoice
        $supplierRequired = $creditAccount === '3311' || $laborType === 'subcontractor_invoice';

        $data = $request->validate([
            'category'       => ['required', 'string'],
            'labor_type'     => ['nullable', 'string', 'in:internal_employee,freelance_contractor,subcontractor_invoice,insurance_allocation'],
            'description'    => ['required', 'string', 'max:255'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'expense_date'   => ['required', 'date'],
            'debit_account'  => ['nullable', 'string', 'max:20'],
            'credit_account' => ['nullable', 'string', 'max:20'],
            // Conditional required based on TK Có / labor_type
            'supplier_id' => [
                \Illuminate\Validation\Rule::requiredIf($supplierRequired),
                'nullable', 'integer', 'exists:suppliers,id',
            ],
            'fund_id' => [
                \Illuminate\Validation\Rule::requiredIf($creditAccount === '1111'),
                'nullable', 'integer', 'exists:funds,id',
            ],
            'bank_account_id' => [
                \Illuminate\Validation\Rule::requiredIf($creditAccount === '1121'),
                'nullable', 'integer', 'exists:bank_accounts,id',
            ],
            'employee_id'             => ['nullable', 'integer', 'exists:employees,id'],
            'invoice_number'          => ['nullable', 'string', 'max:100'],
            'payment_method'          => ['nullable', 'string', 'in:cash,bank,payable,advance,salary,misc,depreciation,insurance'],
            'fixed_asset_id'          => ['nullable', 'integer', 'exists:fixed_assets,id'],
            'vat_rate'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'              => ['nullable', 'integer', 'min:0'],
            'has_vat_invoice'         => ['nullable', 'boolean'],
            'purchase_invoice_id'     => ['nullable', 'integer'],
            'pit_withholding_enabled' => ['nullable', 'boolean'],
            'pit_rate'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Contractor info (freelance_contractor)
            'contractor_name'           => ['nullable', 'string', 'max:255'],
            'contractor_representative' => ['nullable', 'string', 'max:100'],
            'contractor_phone'          => ['nullable', 'string', 'max:50'],
            'contractor_id_number'      => ['nullable', 'string', 'max:50'],
            'contract_number'           => ['nullable', 'string', 'max:100'],
        ]);

        // Ensure vat_amount defaults to 0 (nullable in validation but NOT NULL in DB)
        $data['vat_amount'] = (int) ($data['vat_amount'] ?? 0);

        // freelance_contractor không có hóa đơn VAT → clear VAT
        if (($data['labor_type'] ?? '') === 'freelance_contractor' && empty($data['has_vat_invoice'])) {
            $data['vat_amount'] = 0;
            $data['vat_rate']   = null;
        }

        // Compute PIT amounts server-side (không trust frontend)
        $grossAmount = (int) round((float) ($data['amount'] ?? 0));
        $pitEnabled  = !empty($data['pit_withholding_enabled'])
                       && in_array($data['payment_method'] ?? '', ['cash', 'bank']);
        if ($pitEnabled && !empty($data['pit_rate'])) {
            $data['pit_amount']          = (int) round($grossAmount * (float) $data['pit_rate'] / 100);
            $data['net_payment_amount']  = $grossAmount - $data['pit_amount'];
        } else {
            $data['pit_withholding_enabled'] = false;
            $data['pit_amount']              = 0;
            $data['net_payment_amount']      = 0;
        }

        // Chặn TK 152/156 ở TK Nợ (vật tư phải đi qua phiếu xuất kho)
        $debitAccount = $data['debit_account'] ?? '';
        if ($debitAccount && preg_match('/^15[26]/', $debitAccount)) {
            return back()->withInput()->with(
                'error',
                "TK {$debitAccount} (vật tư/hàng hóa) không được dùng trong Chi phí PS. Sử dụng phiếu xuất kho."
            );
        }

        // Cảnh báo trùng số hóa đơn (không chặn, chỉ flash warning nếu force=0)
        $invoiceNumber = $data['invoice_number'] ?? null;
        $supplierId    = $data['supplier_id'] ?? null;
        if ($invoiceNumber && $supplierId && !$request->boolean('force_duplicate')) {
            $existsPI = \App\Models\PurchaseInvoice::where('supplier_id', $supplierId)
                ->where('invoice_number', $invoiceNumber)
                ->exists();
            $existsPE = ProjectExpense::where('project_id', $project->id)
                ->where('supplier_id', $supplierId)
                ->where('invoice_number', $invoiceNumber)
                ->where('status', '!=', 'cancelled')
                ->exists();
            if ($existsPI || $existsPE) {
                return back()->withInput()->with(
                    'warning_duplicate',
                    "Số hóa đơn {$invoiceNumber} đã tồn tại với NCC này. Nhấn Thêm lần nữa để vẫn ghi nhận."
                );
            }
        }

        try {
            DB::transaction(function () use ($data, $project) {
                $expense = $project->expenses()->create([
                    ...$data,
                    'created_by' => auth()->id(),
                    'status'     => 'draft',
                ]);

                if ((float) $expense->amount > 0) {
                    $expense->loadMissing('project', 'supplier', 'fund', 'bankAccount');
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

        try {
            DB::transaction(function () use ($expense) {
                // 1. Cancel mọi kết chuyển đang posted (tạo JE đảo Nợ TK_chi_phí / Có 154)
                foreach ($expense->transfers()->where('status', 'posted')->get() as $transfer) {
                    $this->transferService->cancelTransfer($transfer, 'Hủy khi xóa chi phí gốc');
                }

                // 2. Cancel WIP entry trực tiếp (nếu TK Nợ là 154 và có wip entry).
                // Phải null journal_entry_id TRƯỚC khi gọi reverseOrDelete,
                // vì draft JE sẽ bị hard-delete và WIP có FK reference tới nó.
                if ($expense->project_wip_entry_id) {
                    ProjectWipEntry::where('id', $expense->project_wip_entry_id)
                        ->update([
                            'status'           => 'cancelled',
                            'cancel_reason'    => 'Hủy theo chi phí PS #' . $expense->id,
                            'cancelled_at'     => now(),
                            'cancelled_by'     => auth()->id(),
                            'journal_entry_id' => null,
                        ]);
                }

                // 3. Reverse hoặc xóa JE gốc của chi phí
                $this->accounting->reverseOrDelete(
                    ProjectExpense::class,
                    $expense->id,
                    'Hủy chi phí dự án: ' . $expense->description
                );

                // 4. Xóa expense
                $expense->delete();
            });
        } catch (\Throwable $e) {
            \Log::error("removeExpense failed project#{$project->id} expense#{$expense->id}: {$e->getMessage()}");
            return back()->with('error', 'Không thể xóa chi phí: ' . $e->getMessage());
        }

        return back()->with('success', 'Đã xóa chi phí và đảo bút toán liên quan.');
    }

    public function expenseEdit(Project $project, ProjectExpense $expense): Response
    {
        abort_unless($expense->project_id === $project->id, 404);
        $expense->load('supplier', 'fund', 'bankAccount');

        return Inertia::render('Projects/Expenses/Edit', [
            'project' => [
                'id'   => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'expense' => [
                'id'                        => $expense->id,
                'category'                  => $expense->category->value,
                'labor_type'                => $expense->labor_type?->value,
                'description'               => $expense->description,
                'amount'                    => (int) round((float) $expense->amount),
                'expense_date'              => $expense->expense_date->format('Y-m-d'),
                'debit_account'             => $expense->debit_account,
                'credit_account'            => $expense->credit_account,
                'payment_method'            => $expense->payment_method ?? 'payable',
                'supplier_id'               => $expense->supplier_id,
                'supplier_name'             => $expense->supplier?->name,
                'fund_id'                   => $expense->fund_id,
                'bank_account_id'           => $expense->bank_account_id,
                'employee_id'               => $expense->employee_id,
                'invoice_number'            => $expense->invoice_number,
                'vat_rate'                  => $expense->vat_rate,
                'vat_amount'                => $expense->vat_amount ?? 0,
                'has_vat_invoice'           => (bool) $expense->has_vat_invoice,
                'pit_withholding_enabled'   => (bool) $expense->pit_withholding_enabled,
                'pit_rate'                  => $expense->pit_rate,
                'pit_amount'                => $expense->pit_amount ?? 0,
                'contractor_name'           => $expense->contractor_name,
                'contractor_representative' => $expense->contractor_representative,
                'contractor_phone'          => $expense->contractor_phone,
                'contractor_id_number'      => $expense->contractor_id_number,
                'contract_number'           => $expense->contract_number,
                'fixed_asset_id'            => $expense->fixed_asset_id,
                'status'                    => $expense->status ?? 'draft',
                'journal_entry_id'          => $expense->journal_entry_id,
                'has_posted_transfers'      => $expense->transfers()->where('status', 'posted')->exists(),
            ],
            'expenseCategories' => collect(ExpenseCategory::cases())->map(fn ($c) => [
                'value'               => $c->value,
                'label'               => $c->label(),
                'defaultDebitAccount' => $c->defaultDebitAccount(),
            ]),
            'funds'        => Fund::where('type', 'cash')->orderBy('name')->get(['id', 'name', 'account_code']),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(['id', 'bank_name', 'account_number', 'account_code']),
            'employees'    => Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get(['id', 'code', 'name']),
            'fixedAssets'  => \App\Models\FixedAsset::whereIn('status', ['active', 'fully_depreciated'])
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function expenseUpdate(Request $request, Project $project, ProjectExpense $expense): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);

        if ($expense->transfers()->where('status', 'posted')->exists()) {
            return back()->with('error', 'Không thể sửa chi phí đã có kết chuyển sang TK 154. Hủy kết chuyển trước.');
        }

        $hasJe = $expense->journal_entry_id !== null;

        if ($hasJe) {
            // Chỉ cho sửa non-financial fields khi đã có bút toán
            $data = $request->validate([
                'description'               => ['required', 'string', 'max:255'],
                'invoice_number'            => ['nullable', 'string', 'max:100'],
                'contractor_name'           => ['nullable', 'string', 'max:255'],
                'contractor_representative' => ['nullable', 'string', 'max:100'],
                'contractor_phone'          => ['nullable', 'string', 'max:50'],
                'contractor_id_number'      => ['nullable', 'string', 'max:50'],
                'contract_number'           => ['nullable', 'string', 'max:100'],
            ]);
            $expense->update($data);
            return redirect()->route('projects.projects.show', $project)->with('success', 'Đã cập nhật thông tin chi phí.');
        }

        // Chưa có JE: full edit
        $creditAccount = $request->input('credit_account', '');
        $laborType     = $request->input('labor_type', '');
        $supplierRequired = $creditAccount === '3311' || $laborType === 'subcontractor_invoice';

        $data = $request->validate([
            'category'                  => ['required', 'string'],
            'labor_type'                => ['nullable', 'string', 'in:internal_employee,freelance_contractor,subcontractor_invoice,insurance_allocation'],
            'description'               => ['required', 'string', 'max:255'],
            'amount'                    => ['required', 'numeric', 'min:0'],
            'expense_date'              => ['required', 'date'],
            'debit_account'             => ['nullable', 'string', 'max:20'],
            'credit_account'            => ['nullable', 'string', 'max:20'],
            'supplier_id' => [
                \Illuminate\Validation\Rule::requiredIf($supplierRequired),
                'nullable', 'integer', 'exists:suppliers,id',
            ],
            'fund_id'                   => ['nullable', 'integer', 'exists:funds,id'],
            'bank_account_id'           => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'employee_id'               => ['nullable', 'integer', 'exists:employees,id'],
            'invoice_number'            => ['nullable', 'string', 'max:100'],
            'payment_method'            => ['nullable', 'string', 'in:cash,bank,payable,advance,salary,misc,depreciation,insurance'],
            'fixed_asset_id'            => ['nullable', 'integer', 'exists:fixed_assets,id'],
            'vat_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount'                => ['nullable', 'integer', 'min:0'],
            'has_vat_invoice'           => ['nullable', 'boolean'],
            'pit_withholding_enabled'   => ['nullable', 'boolean'],
            'pit_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contractor_name'           => ['nullable', 'string', 'max:255'],
            'contractor_representative' => ['nullable', 'string', 'max:100'],
            'contractor_phone'          => ['nullable', 'string', 'max:50'],
            'contractor_id_number'      => ['nullable', 'string', 'max:50'],
            'contract_number'           => ['nullable', 'string', 'max:100'],
            'post_immediately'          => ['nullable', 'boolean'],
        ]);

        $data['vat_amount'] = (int) ($data['vat_amount'] ?? 0);

        if (($data['labor_type'] ?? '') === 'freelance_contractor' && empty($data['has_vat_invoice'])) {
            $data['vat_amount'] = 0;
            $data['vat_rate']   = null;
        }

        $grossAmount = (int) round((float) ($data['amount'] ?? 0));
        $pitEnabled  = !empty($data['pit_withholding_enabled'])
                       && in_array($data['payment_method'] ?? '', ['cash', 'bank']);
        if ($pitEnabled && !empty($data['pit_rate'])) {
            $data['pit_amount']              = (int) round($grossAmount * (float) $data['pit_rate'] / 100);
            $data['net_payment_amount']      = $grossAmount - $data['pit_amount'];
        } else {
            $data['pit_withholding_enabled'] = false;
            $data['pit_amount']              = 0;
            $data['net_payment_amount']      = 0;
        }

        $debitAccount = $data['debit_account'] ?? '';
        if ($debitAccount && preg_match('/^15[26]/', $debitAccount)) {
            return back()->withInput()->with('error', "TK {$debitAccount} (vật tư/hàng hóa) không được dùng trong Chi phí PS.");
        }

        $shouldPost = (bool) ($data['post_immediately'] ?? false);
        unset($data['post_immediately']);

        try {
            DB::transaction(function () use ($data, $expense, $shouldPost) {
                $expense->update($data);

                if ($shouldPost && (float) $expense->amount > 0) {
                    $expense->refresh()->loadMissing('project', 'supplier', 'fund', 'bankAccount');
                    $this->wip->createFromExpense($expense);
                }
            });
        } catch (\Throwable $e) {
            \Log::error("expenseUpdate failed expense#{$expense->id}: {$e->getMessage()}");
            return back()->with('error', 'Không thể cập nhật chi phí: ' . $e->getMessage())->withInput();
        }

        $msg = $shouldPost ? 'Đã cập nhật và ghi nhận chi phí.' : 'Đã lưu nháp chi phí.';
        return redirect()->route('projects.projects.show', $project)->with('success', $msg);
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

    public function expenseCreate(Project $project): Response
    {
        return Inertia::render('Projects/Expenses/Create', [
            'project' => [
                'id'   => $project->id,
                'code' => $project->code,
                'name' => $project->name,
            ],
            'expenseCategories' => collect(ExpenseCategory::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'defaultDebitAccount' => $c->defaultDebitAccount(),
            ]),
            'funds'        => Fund::where('type', 'cash')->orderBy('name')->get(['id', 'name', 'account_code']),
            'bankAccounts' => BankAccount::orderBy('bank_name')->get(['id', 'bank_name', 'account_number', 'account_code']),
            'employees'    => Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get(['id', 'code', 'name']),
            'fixedAssets'  => \App\Models\FixedAsset::whereIn('status', ['active', 'fully_depreciated'])
                ->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function expenseBatchStore(Request $request, Project $project): RedirectResponse
    {
        $paymentMethod = $request->input('payment_method', 'payable');

        $data = $request->validate([
            'expense_date'    => ['required', 'date'],
            'invoice_number'  => ['nullable', 'string', 'max:100'],
            'payment_method'  => ['required', 'string', 'in:cash,bank,payable,advance,salary,misc,depreciation,insurance'],
            'description'     => ['nullable', 'string', 'max:500'],
            'post_immediately' => ['nullable', 'boolean'],
            'supplier_id' => [
                \Illuminate\Validation\Rule::requiredIf($paymentMethod === 'payable'),
                'nullable', 'integer', 'exists:suppliers,id',
            ],
            'fund_id' => [
                \Illuminate\Validation\Rule::requiredIf($paymentMethod === 'cash'),
                'nullable', 'integer', 'exists:funds,id',
            ],
            'bank_account_id' => [
                \Illuminate\Validation\Rule::requiredIf($paymentMethod === 'bank'),
                'nullable', 'integer', 'exists:bank_accounts,id',
            ],
            'fixed_asset_id' => [
                \Illuminate\Validation\Rule::requiredIf($paymentMethod === 'depreciation'),
                'nullable', 'integer', 'exists:fixed_assets,id',
            ],
            'credit_account' => [
                \Illuminate\Validation\Rule::requiredIf($paymentMethod === 'insurance'),
                'nullable', 'string', 'max:20',
                \Illuminate\Validation\Rule::when($paymentMethod === 'insurance', ['regex:/^338/']),
            ],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Contractor info — header-level (shared across all lines in batch)
            'contractor_name'           => ['nullable', 'string', 'max:255'],
            'contractor_representative' => ['nullable', 'string', 'max:100'],
            'contractor_phone'          => ['nullable', 'string', 'max:50'],
            'contractor_id_number'      => ['nullable', 'string', 'max:50'],
            'contract_number'           => ['nullable', 'string', 'max:100'],
            'lines'       => ['required', 'array', 'min:1'],
            'lines.*.category'           => ['required', 'string'],
            'lines.*.description'        => ['required', 'string', 'max:255'],
            'lines.*.amount'             => ['required', 'numeric', 'min:0'],
            'lines.*.debit_account'      => ['nullable', 'string', 'max:20'],
            'lines.*.vat_rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.vat_amount'         => ['nullable', 'integer', 'min:0'],
            'lines.*.has_vat_invoice'    => ['nullable', 'boolean'],
            'lines.*.labor_type'         => ['nullable', 'string', 'in:internal_employee,freelance_contractor,subcontractor_invoice,insurance_allocation'],
            'lines.*.notes'              => ['nullable', 'string', 'max:500'],
            'lines.*.pit_withholding_enabled' => ['nullable', 'boolean'],
            'lines.*.pit_rate'               => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $shouldPost = (bool) ($data['post_immediately'] ?? true);

        // Resolve credit_account once per batch (header-level: same payment method for all lines)
        $creditAcct = $this->resolveExpenseCreditAccount($data);

        try {
            DB::transaction(function () use ($data, $project, $shouldPost, $creditAcct) {
                foreach ($data['lines'] as $line) {
                    $grossAmount = (int) round((float) $line['amount']);

                    // Always resolve debit_account from category when not provided
                    $debitAcct = $line['debit_account']
                        ?: ExpenseCategory::from($line['category'])->defaultDebitAccount();

                    // Chặn TK 152/156
                    if (preg_match('/^15[26]/', $debitAcct)) {
                        throw new \InvalidArgumentException(
                            "TK {$debitAcct} (vật tư/hàng hóa) không được dùng trong Chi phí PS. Sử dụng phiếu xuất kho."
                        );
                    }

                    $pitEnabled = !empty($line['pit_withholding_enabled'])
                                  && in_array($data['payment_method'], ['cash', 'bank'])
                                  && !empty($line['pit_rate']);
                    $pitAmount  = $pitEnabled ? (int) round($grossAmount * (float) $line['pit_rate'] / 100) : 0;

                    // freelance_contractor không có HĐ VAT → clear VAT
                    $lineLaborType   = $line['labor_type'] ?? null;
                    $lineHasVat      = !empty($line['has_vat_invoice']);
                    $isFreelanceNoVat = $lineLaborType === 'freelance_contractor' && !$lineHasVat;
                    $vatRate   = $isFreelanceNoVat ? null : ($line['vat_rate'] ?? null);
                    $vatAmount = $isFreelanceNoVat ? 0   : (int) ($line['vat_amount'] ?? 0);

                    $expense = $project->expenses()->create([
                        'category'                => $line['category'],
                        'labor_type'              => $lineLaborType,
                        'description'             => $line['description'],
                        'amount'                  => $grossAmount,
                        'expense_date'            => $data['expense_date'],
                        'debit_account'           => $debitAcct,
                        'credit_account'          => $creditAcct,
                        'vat_rate'                => $vatRate,
                        'vat_amount'              => $vatAmount,
                        'has_vat_invoice'         => $lineHasVat,
                        'payment_method'          => $data['payment_method'],
                        'supplier_id'             => $data['supplier_id'] ?? null,
                        'fund_id'                 => $data['fund_id'] ?? null,
                        'bank_account_id'         => $data['bank_account_id'] ?? null,
                        'employee_id'             => $data['employee_id'] ?? null,
                        'fixed_asset_id'          => $data['fixed_asset_id'] ?? null,
                        'invoice_number'          => $data['invoice_number'] ?? null,
                        'contractor_name'           => $data['contractor_name'] ?? null,
                        'contractor_representative' => $data['contractor_representative'] ?? null,
                        'contractor_phone'          => $data['contractor_phone'] ?? null,
                        'contractor_id_number'      => $data['contractor_id_number'] ?? null,
                        'contract_number'           => $data['contract_number'] ?? null,
                        'pit_withholding_enabled' => $pitEnabled,
                        'pit_rate'                => $pitEnabled ? ($line['pit_rate'] ?? 0) : 0,
                        'pit_amount'              => $pitAmount,
                        'net_payment_amount'      => $pitEnabled ? max(0, $grossAmount - $pitAmount) : 0,
                        'status'                  => 'draft',
                        'created_by'              => auth()->id(),
                    ]);

                    if ($shouldPost && $grossAmount > 0) {
                        $expense->loadMissing('project', 'supplier', 'fund', 'bankAccount');
                        $this->wip->createFromExpense($expense, useCashVoucher: true);
                    }
                }
            });
        } catch (\Throwable $e) {
            \Log::error("expenseBatchStore failed project#{$project->id}: {$e->getMessage()}");
            return back()->with('error', 'Không thể ghi nhận chi phí: ' . $e->getMessage())->withInput();
        }

        $count = count($data['lines']);
        $msg   = $shouldPost
            ? "Đã ghi nhận {$count} dòng chi phí phát sinh."
            : "Đã lưu nháp {$count} dòng chi phí.";

        return redirect()->route('projects.projects.show', $project)
            ->with('success', $msg);
    }

    /** Xác định TK Có cho chi phí PS từ dữ liệu header (không cần model đã save). */
    private function resolveExpenseCreditAccount(array $data): string
    {
        $method = $data['payment_method'] ?? 'payable';

        if ($method === 'cash') {
            if (!empty($data['fund_id'])) {
                return \App\Models\Fund::find($data['fund_id'])?->account_code
                    ?? AccountingSettings::get('cash_account', '1111');
            }
            return AccountingSettings::get('cash_account', '1111');
        }
        if ($method === 'bank') {
            if (!empty($data['bank_account_id'])) {
                return \App\Models\BankAccount::find($data['bank_account_id'])?->account_code
                    ?? AccountingSettings::get('bank_account', '1121');
            }
            return AccountingSettings::get('bank_account', '1121');
        }
        if ($method === 'advance')      return '141';
        if ($method === 'salary')       return AccountingSettings::get('salary_payable_account', '3341');
        if ($method === 'misc')         return '3388';
        if ($method === 'depreciation') return '214';
        if ($method === 'insurance') {
            // credit_account chứa TK 338 chi tiết được chọn trực tiếp từ form
            return $data['credit_account'] ?? '33831';
        }

        // payable (default)
        if (!empty($data['supplier_id'])) {
            return \App\Models\Supplier::find($data['supplier_id'])?->payable_account_code ?? '3311';
        }
        return '3311';
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
