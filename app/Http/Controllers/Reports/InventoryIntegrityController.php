<?php

namespace App\Http\Controllers\Reports;

use App\Exports\InventoryIntegrity\InventoryIntegrityExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryAuditRun;
use App\Models\InventoryIntegrityIssue as Issue;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Integrity\IntegrityReportQuery;
use App\Services\Inventory\Integrity\IntegrityRuleCatalog;
use App\Services\Inventory\Integrity\IntegrityTraceService;
use App\Services\Inventory\Integrity\InventoryIntegrityAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/** Báo cáo → Kho → Kiểm soát sai lệch tồn kho và kế toán. Xem/drill-down/audit/xuất Excel. */
class InventoryIntegrityController extends Controller
{
    private const FILTERS = ['search', 'status', 'rule_code', 'category', 'severity', 'warehouse_id', 'project_id', 'assignee_id',
        'product_id', 'document', 'group', 'bucket', 'sort', 'direction'];

    public function __construct(private IntegrityReportQuery $query, private IntegrityTraceService $trace) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only(self::FILTERS);
        return Inertia::render('Reports/InventoryIntegrity/Index', [
            'issues' => $this->query->filtered($user, $filters)->paginate(30)->withQueryString()->through(fn (Issue $i) => $this->row($i)),
            'kpis' => $this->query->kpis($user),
            'lastRun' => $this->runDto(InventoryAuditRun::lastSuccess()),
            'lastAllRun' => $this->runDto(InventoryAuditRun::where('status', 'success')->where('scope_type', 'all')->latest('id')->first()),
            'lastFailed' => $this->runDto(InventoryAuditRun::where('status', 'failed')->latest('id')->first()),
            'filters' => $filters,
            'rules' => IntegrityRuleCatalog::all(),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'assignees' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Issue::STATUSES, 'manualStatuses' => Issue::MANUAL_STATUSES, 'categories' => Issue::CATEGORIES,
            'can' => [
                'audit' => $user->can('reports.inventory_integrity.audit'), 'assign' => $user->can('reports.inventory_integrity.assign'),
                'note' => $user->can('reports.inventory_integrity.note'), 'accept' => $user->can('reports.inventory_integrity.accept'),
                'export' => $user->can('reports.inventory_integrity.export'), 'accounting' => $user->can('accounting.view'),
            ],
        ]);
    }

    public function show(Request $request, Issue $issue): JsonResponse
    {
        $this->authorizeVisible($request, $issue);
        $ev = $issue->evidence ?? [];
        return response()->json([
            'issue' => $this->row($issue->load(['product:id,code,name', 'warehouse:id,name', 'project:id,code', 'assignee:id,name'])) + [
                'evidence' => $ev, 'case_info' => $issue->case_info, 'resolution' => $issue->resolution, 'request_note' => $issue->request_note,
                'rule' => IntegrityRuleCatalog::meta($issue->rule_code),
                'document_link' => $this->trace->docLink($ev['doc_type'] ?? null, isset($ev['doc_id']) ? (int) $ev['doc_id'] : null, $request->user()),
            ],
            'trace' => $issue->source === 'auto' ? $this->trace->trace($issue, $request->user()) : null,
            'logs' => $issue->logs()->with('user:id,name')->limit(200)->get()->map(fn ($l) => [
                'action' => $l->action, 'from' => $l->from_status, 'to' => $l->to_status, 'note' => $l->note, 'payload' => $l->payload,
                'user' => $l->user?->name ?? 'Hệ thống', 'run_id' => $l->run_id, 'at' => $l->created_at?->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function run(Request $request, InventoryIntegrityAuditor $auditor): RedirectResponse
    {
        $data = $request->validate(['scope' => 'required|in:all,product,warehouse,project,document,issue', 'value' => 'nullable|string|max:100']);
        if ($data['scope'] === 'issue') {
            $this->authorizeVisible($request, Issue::findOrFail((int) $data['value']));
        }
        try {
            $run = $auditor->run($data['scope'], $data['value'] ?? null, $request->user()->id);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return $run->status === 'success'
            ? back()->with('success', "Đã kiểm tra (run #{$run->id}): phát hiện {$run->issues_found}, mới {$run->issues_opened}, tự đóng {$run->issues_resolved}, mở lại {$run->issues_reopened}.")
            : back()->with('error', "Kiểm tra thất bại (run #{$run->id}) — kết quả trước đó được giữ nguyên. Lỗi: {$run->error}");
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $filters = $request->only(self::FILTERS);
        $issues = $this->query->filtered($user, $filters)->get();
        return Excel::download(new InventoryIntegrityExport($issues, $this->query->kpis($user), InventoryAuditRun::lastSuccess(), $filters),
            'kiem-soat-sai-lech-ton-kho-' . now()->format('Ymd-His') . '.xlsx');
    }

    public static function authorizeVisible(Request $request, Issue $issue): void
    {
        abort_unless(app(IntegrityReportQuery::class)->visible($request->user())->whereKey($issue->id)->exists(), 403);
    }

    private function row(Issue $i): array
    {
        return [
            'id' => $i->id, 'code' => 'VD-' . $i->id, 'rule_code' => $i->rule_code, 'source' => $i->source, 'category' => $i->category,
            'severity' => $i->severity, 'status' => $i->status, 'bucket' => IntegrityReportQuery::bucket($i), 'is_legacy' => $i->is_legacy,
            'product_id' => $i->product_id, 'product_code' => $i->product?->code, 'product_name' => $i->product?->name,
            'warehouse_id' => $i->warehouse_id, 'warehouse' => $i->warehouse?->name, 'project' => $i->project?->code,
            'document_ref' => $i->document_ref, 'document_codes' => $i->document_codes,
            'qty_book' => $i->qty_book, 'qty_avco' => $i->qty_avco, 'qty_lot' => $i->qty_lot, 'qty_available' => $i->qty_available, 'qty_diff' => $i->qty_diff,
            'value_book' => $i->value_book, 'value_compare' => $i->value_compare, 'value_diff' => $i->value_diff, 'value_basis' => $i->value_basis,
            'cause' => $i->cause, 'proposal' => $i->proposal, 'case_summary' => $i->case_info['summary'] ?? null,
            'assignee' => $i->assignee?->name, 'assignee_id' => $i->assignee_id, 'due_date' => $i->due_date?->format('Y-m-d'),
            'detected_at' => $i->detected_at?->format('d/m/Y H:i'), 'last_checked_at' => $i->last_checked_at?->format('d/m/Y H:i'), 'reopen_count' => $i->reopen_count,
        ];
    }

    private function runDto(?InventoryAuditRun $r): ?array
    {
        return $r ? ['id' => $r->id, 'scope_type' => $r->scope_type, 'scope_value' => $r->scope_value, 'status' => $r->status,
            'rules_version' => $r->rules_version, 'finished_at' => $r->finished_at?->format('d/m/Y H:i:s'), 'products_checked' => $r->products_checked,
            'warehouses_checked' => $r->warehouses_checked, 'rows_checked' => $r->rows_checked, 'issues_found' => $r->issues_found,
            'legacy_rows' => $r->summary['legacy_rows'] ?? null, 'error' => $r->error] : null;
    }
}
