<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SmallToolStatus;
use App\Exports\SmallToolListExport;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\SmallToolImport;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class SmallToolController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    private function filteredQuery(Request $request)
    {
        return SmallTool::with(['category', 'responsibleEmployee', 'warehouse', 'project', 'supplier'])
            ->when($request->search, fn ($q, $s) =>
                $q->where(fn ($q2) => $q2->where('code', 'ilike', "%{$s}%")->orWhere('name', 'ilike', "%{$s}%"))
            )
            ->when($request->status,      fn ($q) => $q->where('status', $request->status))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->department,  fn ($q) => $q->where('department', 'ilike', '%' . $request->department . '%'))
            ->when($request->project_id,  fn ($q) => $q->where('project_id', $request->project_id))
            ->when($request->warehouse_id, fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->orderByDesc('id');
    }

    public function index(Request $request): Response
    {
        $this->authorize('ccdc.view');

        return Inertia::render('Accounting/SmallTools/Index', $this->indexProps($request));
    }

    public function create(): Response
    {
        $this->authorize('ccdc.manage');
        return Inertia::render('Accounting/SmallTools/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $this->validateTool($request);
        $data['code']       = SmallTool::generateCode();
        $data['created_by'] = auth()->id();
        $data['status']     = 'draft';

        $tool = SmallTool::create($data);

        // Nếu mua dùng ngay: confirm luôn
        if ($request->auto_confirm && $tool->acquisition_type === 'direct') {
            $this->service->confirmDirectTool($tool);
        }

        return redirect()->route('accounting.small-tools.show', $tool)
            ->with('success', 'Đã tạo hồ sơ CCDC.');
    }

    public function show(SmallTool $tool): Response
    {
        $this->authorize('ccdc.view');

        $tool->load([
            'category', 'responsibleEmployee', 'warehouse', 'project',
            'supplier', 'purchaseInvoice', 'fund',
            'allocations', 'transfers.fromEmployee', 'transfers.toEmployee',
            'transfers.fromProject', 'transfers.toProject',
            'disposals', 'pausedByUser', 'resumedByUser',
        ]);

        $history = \Spatie\Activitylog\Models\Activity::forSubject($tool)
            ->with('causer')->latest()->get()->map(fn ($log) => [
                'description' => $log->description,
                'causer_name' => $log->causer?->name ?? 'Hệ thống',
                'created_at'  => $log->created_at->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Accounting/SmallTools/Show', [
            'tool'        => $this->toDto($tool),
            'history'     => $history,
            'allocations' => $tool->allocations->map(fn ($a) => [
                'id'                 => $a->id,
                'period'             => $a->period,
                'amount'             => (float) $a->amount,
                'accumulated_before' => (float) $a->accumulated_before,
                'remaining_after'    => (float) $a->remaining_after,
                'debit_account'      => $a->debit_account,
                'credit_account'     => $a->credit_account,
                'status'             => $a->status,
                'posted_at'          => $a->posted_at?->format('Y-m-d'),
            ]),
            'transfers'   => $tool->transfers->map(fn ($t) => [
                'id'              => $t->id,
                'code'            => $t->code,
                'transfer_date'   => $t->transfer_date->format('Y-m-d'),
                'from_department' => $t->from_department,
                'to_department'   => $t->to_department,
                'from_employee'   => $t->fromEmployee?->name,
                'to_employee'     => $t->toEmployee?->name,
                'from_project'    => $t->fromProject?->name,
                'to_project'      => $t->toProject?->name,
                'reason'          => $t->reason,
            ]),
            'disposals'   => $tool->disposals->map(fn ($d) => [
                'id'           => $d->id,
                'code'         => $d->code,
                'disposal_type' => $d->disposal_type,
                'type_label'   => $d->disposalTypeLabel(),
                'disposal_date' => $d->disposal_date->format('Y-m-d'),
                'net_value'    => (float) $d->net_value_snapshot,
                'status'       => $d->status,
                'reason'       => $d->reason,
            ]),
            'statuses'    => collect(SmallToolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function edit(SmallTool $tool): Response
    {
        $this->authorize('ccdc.manage');
        return Inertia::render('Accounting/SmallTools/Form', array_merge(
            $this->formProps(),
            ['tool' => $this->toDto($tool)]
        ));
    }

    public function update(Request $request, SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        if (! in_array($tool->status->value, ['draft', 'in_stock'])) {
            return back()->withErrors(['error' => 'Không thể sửa CCDC đang sử dụng hoặc đã xử lý.']);
        }

        $data = $this->validateTool($request, $tool);
        $data['updated_by'] = auth()->id();
        $tool->update($data);

        return redirect()->route('accounting.small-tools.show', $tool)
            ->with('success', 'Đã cập nhật hồ sơ CCDC.');
    }

    public function confirm(SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');
        $this->service->confirmDirectTool($tool);
        return back()->with('success', 'Đã ghi nhận bút toán CCDC dùng ngay.');
    }

    public function destroy(Request $request, SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.delete');

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $blockers = [];

        if (\App\Models\SmallToolReceiptItem::where('small_tool_id', $tool->id)->exists()) {
            $blockers[] = 'phiếu nhập kho';
        }
        if (\App\Models\SmallToolIssueItem::where('small_tool_id', $tool->id)->exists()) {
            $blockers[] = 'phiếu xuất dùng';
        }
        if ($tool->transfers()->exists()) {
            $blockers[] = 'lịch sử điều chuyển';
        }
        if ($tool->disposals()->exists()) {
            $blockers[] = 'phiếu ghi giảm/thanh lý';
        }
        if ($tool->allocations()->whereIn('status', ['posted', 'reversed'])->exists()) {
            $blockers[] = 'kỳ phân bổ đã phát sinh bút toán (posted/reversed)';
        }

        if ($tool->acquisition_journal_entry_id) {
            $blockers[] = 'bút toán tăng CCDC';
        }
        if ($tool->issue_journal_entry_id) {
            $blockers[] = 'bút toán xuất dùng';
        }

        if ($blockers) {
            return back()->with('error',
                'Không thể xóa CCDC ' . $tool->code . ' — đã liên kết: ' . implode(', ', $blockers) .
                '. Hãy hủy/đảo các chứng từ này trước khi xóa.'
            );
        }

        DB::transaction(function () use ($tool, $data) {
            activity('small_tool')
                ->causedBy(auth()->user())
                ->withProperties([
                    'code'          => $tool->code,
                    'name'          => $tool->name,
                    'original_cost' => (float) $tool->original_cost,
                    'reason'        => $data['reason'],
                ])
                ->log("Xóa CCDC {$tool->code} — Lý do: {$data['reason']}");

            $tool->allocations()->delete();
            $tool->delete();
        });

        return redirect()->route('accounting.small-tools.index')->with('success', "Đã xóa CCDC {$tool->code}.");
    }

    public function exportExcel(Request $request)
    {
        $this->authorize('ccdc.view');

        $tools = $this->filteredQuery($request)->get();

        return Excel::download(
            new SmallToolListExport($tools, $request->only(['search', 'status', 'department'])),
            'danh-sach-ccdc_' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        $this->authorize('ccdc.view');

        $tools = $this->filteredQuery($request)->get();

        $rows = $tools->map(fn (SmallTool $t) => [
            'code'            => $t->code,
            'name'            => $t->name,
            'category_name'   => $t->category?->name,
            'department'      => $t->department,
            'status_label'    => $t->status->label(),
            'original_cost'   => (float) $t->original_cost,
            'total_allocated' => (float) $t->total_allocated,
            'total_remaining' => (float) $t->total_remaining,
        ])->all();

        $totals = [
            'original_cost'   => array_sum(array_column($rows, 'original_cost')),
            'total_allocated' => array_sum(array_column($rows, 'total_allocated')),
            'total_remaining' => array_sum(array_column($rows, 'total_remaining')),
        ];

        $parts = [];
        if ($request->search)     $parts[] = 'Từ khóa: "' . $request->search . '"';
        if ($request->status)     $parts[] = 'Trạng thái: ' . $request->status;
        if ($request->department) $parts[] = 'Bộ phận: ' . $request->department;
        $filterDescription = $parts ? implode(' | ', $parts) : 'Tất cả CCDC';

        $company = \App\Models\Setting::getGroup('company');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.small-tools-list', compact('rows', 'totals', 'filterDescription', 'company'))
            ->setPaper('a4', 'landscape')
            ->download('danh-sach-ccdc_' . now()->format('Y-m-d') . '.pdf');
    }

    public function importTemplate()
    {
        $this->authorize('ccdc.manage');

        $headers = [
            'name', 'category_code', 'unit', 'quantity', 'original_cost', 'vat_amount',
            'acquisition_type', 'recognition_method', 'allocation_periods',
            'department', 'employee_code', 'warehouse', 'project_code', 'supplier_code',
            'purchase_date', 'in_service_date', 'notes',
        ];

        $sampleRows = [
            ['[Hướng dẫn] Bắt buộc: name, original_cost. acquisition_type = stock|direct (mặc định stock). recognition_method = immediate|allocation (mặc định immediate).'],
            ['allocation_periods bắt buộc > 0 nếu recognition_method = allocation. Các mã category_code/employee_code/project_code/supplier_code/warehouse không tìm thấy sẽ bị bỏ trống (cảnh báo), không chặn import.'],
            ['Xóa 2 dòng hướng dẫn này trước khi import. CCDC nhập vào luôn ở trạng thái nháp — cần vào từng bản ghi để xác nhận/nhập kho như bình thường.'],
            ['Máy đếm tiền 701', 'DC', 'cái', 1, 5453704, 436296, 'direct', 'allocation', 24, 'Kế toán', '', '', '', '2026-07-01', '2026-07-01', ''],
            ['Máy tính Casio', 'DC', 'cái', 5, 500000, 0, 'stock', 'immediate', '', 'Kinh doanh', '', 'Kho chính', '', 'NCC-0001', '', '', ''],
        ];

        return Excel::download(new TemplateExport($headers, 'CCDC', $sampleRows), 'mau-nhap-ccdc.xlsx');
    }

    public function importPreview(Request $request): Response
    {
        $this->authorize('ccdc.manage');

        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv', 'max:10240'],
        ]);

        $categories = SmallToolCategory::all(['id', 'code', 'name']);
        $employees  = Employee::where('status', 'active')->get(['id', 'code', 'name']);
        $warehouses = Warehouse::all(['id', 'name']);
        $projects   = Project::all(['id', 'code', 'name']);
        $suppliers  = Supplier::where('is_active', true)->get(['id', 'code', 'name']);

        $import = new SmallToolImport($categories, $employees, $warehouses, $projects, $suppliers);
        Excel::import($import, $request->file('file'));

        session(['ccdc_import' => $import->parsedTools]);

        return Inertia::render('Accounting/SmallTools/Index', array_merge(
            $this->indexProps($request),
            [
                'preview' => [
                    'total_rows'  => $import->totalRows,
                    'valid_tools' => count($import->parsedTools),
                    'error_count' => count($import->errors),
                    'warning_count' => count($import->warnings),
                    'tools'       => $import->parsedTools,
                    'errors'      => $import->errors,
                    'warnings'    => $import->warnings,
                ],
            ]
        ));
    }

    public function importConfirm(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $parsedTools = session('ccdc_import', []);
        if (empty($parsedTools)) {
            return back()->with('error', 'Phiên import đã hết hạn. Vui lòng upload lại file.');
        }

        $created = 0;

        DB::transaction(function () use ($parsedTools, &$created) {
            foreach ($parsedTools as $row) {
                SmallTool::create([
                    'code'                    => SmallTool::generateCode(),
                    'name'                    => $row['name'],
                    'category_id'             => $row['category_id'],
                    'unit'                    => $row['unit'],
                    'quantity'                => $row['quantity'],
                    'original_cost'           => $row['original_cost'],
                    'vat_amount'              => $row['vat_amount'],
                    'total_cost'              => $row['total_cost'],
                    'acquisition_type'        => $row['acquisition_type'],
                    'recognition_method'      => $row['recognition_method'],
                    'allocation_periods'      => $row['allocation_periods'],
                    'department'              => $row['department'],
                    'responsible_employee_id' => $row['responsible_employee_id'],
                    'warehouse_id'            => $row['warehouse_id'],
                    'project_id'              => $row['project_id'],
                    'supplier_id'             => $row['supplier_id'],
                    'purchase_date'           => $row['purchase_date'],
                    'in_service_date'         => $row['in_service_date'],
                    'notes'                   => $row['notes'],
                    'status'                  => 'draft',
                    'created_by'              => auth()->id(),
                ]);
                $created++;
            }
        });

        session()->forget('ccdc_import');

        return redirect()->route('accounting.small-tools.index')
            ->with('success', "Đã import {$created} CCDC (trạng thái nháp) — vào từng bản ghi để xác nhận/nhập kho như bình thường.");
    }

    private function indexProps(Request $request): array
    {
        $tools = $this->filteredQuery($request)->paginate(50)->through(fn (SmallTool $t) => $this->toDto($t));

        return [
            'tools'       => $tools,
            'categories'  => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'statuses'    => collect(SmallToolStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'warehouses'  => Warehouse::orderBy('name')->get(['id', 'name']),
            'filters'     => $request->only(['search', 'status', 'category_id', 'department', 'project_id', 'warehouse_id']),
        ];
    }

    private function toDto(SmallTool $t): array
    {
        return [
            'id'                           => $t->id,
            'code'                         => $t->code,
            'name'                         => $t->name,
            'category_id'                  => $t->category_id,
            'category_name'                => $t->category?->name,
            'unit'                         => $t->unit,
            'quantity'                     => $t->quantity,
            'original_cost'                => (float) $t->original_cost,
            'vat_amount'                   => (float) $t->vat_amount,
            'total_cost'                   => (float) $t->total_cost,
            'acquisition_type'             => $t->acquisition_type,
            'recognition_method'           => $t->recognition_method,
            'allocation_periods'           => $t->allocation_periods,
            'allocation_start_date'        => $t->allocation_start_date?->format('Y-m-d'),
            'purchase_date'                => $t->purchase_date?->format('Y-m-d'),
            'in_service_date'              => $t->in_service_date?->format('Y-m-d'),
            'department'                   => $t->department,
            'responsible_employee_id'      => $t->responsible_employee_id,
            'responsible_employee_name'    => $t->responsibleEmployee?->name,
            'warehouse_id'                 => $t->warehouse_id,
            'warehouse_name'               => $t->warehouse?->name,
            'project_id'                   => $t->project_id,
            'project_name'                 => $t->project?->name,
            'supplier_id'                  => $t->supplier_id,
            'supplier_name'                => $t->supplier?->name,
            'purchase_invoice_id'          => $t->purchase_invoice_id,
            'payment_type'                 => $t->payment_type,
            'fund_id'                      => $t->fund_id,
            'stock_account_code'           => $t->stock_account_code,
            'pending_account_code'         => $t->pending_account_code,
            'expense_account_code'         => $t->expense_account_code,
            'payable_account_code'         => $t->payable_account_code,
            'periods_allocated'            => $t->periods_allocated,
            'total_allocated'              => (float) $t->total_allocated,
            'total_remaining'              => $t->total_remaining,
            'monthly_allocation_amount'    => $t->monthly_allocation_amount,
            'acquisition_journal_entry_id' => $t->acquisition_journal_entry_id,
            'issue_journal_entry_id'       => $t->issue_journal_entry_id,
            'status'                       => $t->status->value,
            'status_label'                 => $t->status->label(),
            'status_color'                 => $t->status->color(),
            'notes'                        => $t->notes,
            'is_opening_balance'           => $t->is_opening_balance,
            'opening_balance_period'       => $t->opening_balance_period,
            'allocation_status'            => $t->allocation_status,
            'pause_reason'                 => $t->pause_reason,
            'pause_effective_period'       => $t->pause_effective_period,
            'paused_at'                    => $t->paused_at?->format('d/m/Y H:i'),
            'paused_by_name'               => $t->pausedByUser?->name,
            'resumed_at'                   => $t->resumed_at?->format('d/m/Y H:i'),
            'resumed_by_name'              => $t->resumedByUser?->name,
            'can_pause'                    => $t->canPauseAllocation(),
            'can_resume'                   => $t->canResumeAllocation(),
        ];
    }

    private function formProps(): array
    {
        return [
            'nextCode'   => SmallTool::generateCode(),
            'categories' => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'employees'  => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'projects'   => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get(['id', 'name', 'code']),
            'funds'      => Fund::orderBy('name')->get(['id', 'name', 'type', 'account_code']),
        ];
    }

    private function validateTool(Request $request, ?SmallTool $existing = null): array
    {
        return $request->validate([
            'name'                    => 'required|string|max:255',
            'category_id'             => 'nullable|exists:small_tool_categories,id',
            'unit'                    => 'nullable|string|max:30',
            'quantity'                => 'required|integer|min:1',
            'original_cost'           => 'required|numeric|min:0',
            'vat_amount'              => 'nullable|numeric|min:0',
            'total_cost'              => 'nullable|numeric|min:0',
            'acquisition_type'        => 'required|in:stock,direct',
            'recognition_method'      => 'required|in:immediate,allocation',
            'allocation_periods'      => 'nullable|integer|min:1',
            'allocation_start_date'   => 'nullable|date',
            'purchase_date'           => 'nullable|date',
            'in_service_date'         => 'nullable|date',
            'department'              => 'nullable|string|max:100',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'warehouse_id'            => 'nullable|exists:warehouses,id',
            'project_id'              => 'nullable|exists:projects,id',
            'supplier_id'             => 'nullable|exists:suppliers,id',
            'purchase_invoice_id'     => 'nullable|exists:purchase_invoices,id',
            'payment_type'            => 'required|in:payable,cash,bank',
            'fund_id'                 => 'nullable|exists:funds,id',
            'stock_account_code'      => 'nullable|string|max:20',
            'pending_account_code'    => 'nullable|string|max:20',
            'expense_account_code'    => 'nullable|string|max:20',
            'payable_account_code'    => 'nullable|string|max:20',
            'notes'                   => 'nullable|string',
        ]);
    }
}
