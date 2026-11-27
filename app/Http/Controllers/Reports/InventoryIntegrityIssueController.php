<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\InventoryIntegrityIssue as Issue;
use App\Services\Inventory\Integrity\IntegrityIssueService;
use App\Services\Inventory\Integrity\IntegrityReportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Giao việc, trạng thái, ghi chú, kết quả kế toán, chấp nhận chênh lệch. Không sửa dữ liệu kho/kế toán. */
class InventoryIntegrityIssueController extends Controller
{
    public function __construct(private IntegrityIssueService $service) {}

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1', 'ids.*' => 'integer', 'assignee_id' => 'nullable|exists:users,id',
            'request_note' => 'nullable|string|max:2000', 'due_date' => 'nullable|date', 'category' => 'nullable|string',
        ]);
        $ids = app(IntegrityReportQuery::class)->visible($request->user())->whereIn('id', $data['ids'])->pluck('id')->all();
        $n = $this->service->assign($ids, $data['assignee_id'] ?? null, $data['request_note'] ?? null, $data['due_date'] ?? null, $data['category'] ?? null);
        return back()->with('success', "Đã cập nhật giao việc cho {$n} vấn đề.");
    }

    public function status(Request $request, Issue $issue): RedirectResponse
    {
        InventoryIntegrityController::authorizeVisible($request, $issue);
        $data = $request->validate(['status' => 'required|string', 'note' => 'nullable|string|max:2000']);
        $this->service->changeStatus($issue, $data['status'], $data['note'] ?? null);
        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    public function note(Request $request, Issue $issue): RedirectResponse
    {
        InventoryIntegrityController::authorizeVisible($request, $issue);
        $this->service->addNote($issue, $request->validate(['note' => 'required|string|max:4000'])['note']);
        return back()->with('success', 'Đã thêm ghi chú.');
    }

    public function resolution(Request $request, Issue $issue): RedirectResponse
    {
        InventoryIntegrityController::authorizeVisible($request, $issue);
        $data = $request->validate([
            'check_result' => 'nullable|string|max:4000', 'cause' => 'nullable|string|max:4000', 'chosen_option' => 'nullable|string|max:2000',
            'adjustment_ref' => 'nullable|string|max:255', 'business_note' => 'nullable|string|max:4000',
        ]);
        $this->service->saveResolution($issue, $data);
        return back()->with('success', 'Đã lưu kết quả kiểm tra.');
    }

    public function accept(Request $request, Issue $issue): RedirectResponse
    {
        InventoryIntegrityController::authorizeVisible($request, $issue);
        $data = $request->validate(['justification' => 'required|string|min:10|max:4000']);
        $issue->source === 'policy'
            ? $this->service->decidePolicy($issue, $data['justification'])
            : $this->service->accept($issue, $data['justification']);
        return back()->with('success', $issue->source === 'policy' ? 'Đã ghi nhận quyết định chính sách.' : 'Đã xác nhận chênh lệch hợp lệ.');
    }
}
