<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectExtraCostTransfer;
use App\Models\ProjectExpense;
use App\Services\ProjectExtraCostTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectExtraCostTransferController extends Controller
{
    public function __construct(private ProjectExtraCostTransferService $service) {}

    /** Preview bút toán kết chuyển (không lưu) */
    public function preview(Request $request, Project $project, ProjectExpense $expense): JsonResponse
    {
        abort_unless($expense->project_id === $project->id, 404);

        $data = $request->validate([
            'amount'        => ['nullable', 'numeric', 'min:1'],
            'debit_account' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            return response()->json($this->service->previewTransfer($expense, $data));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Thực hiện kết chuyển */
    public function store(Request $request, Project $project, ProjectExpense $expense): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);
        $this->authorize('projects.manage');

        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'amount'        => ['required', 'numeric', 'min:1'],
            'debit_account' => ['nullable', 'string', 'max:20'],
            'description'   => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->transferTo154($expense, $data);
        } catch (\Throwable $e) {
            return back()->with('error', 'Không thể kết chuyển: ' . $e->getMessage())->withInput();
        }

        return back()->with('success', 'Đã kết chuyển sang TK 154.');
    }

    /** Preview kết chuyển nhiều chi phí */
    public function previewBatch(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'expense_ids'   => ['required', 'array', 'min:1'],
            'expense_ids.*' => ['required', 'integer'],
            'amounts'       => ['nullable', 'array'],
            'amounts.*'     => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            return response()->json(
                $this->service->previewBatch($project, $data['expense_ids'], $data['amounts'] ?? [])
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Kết chuyển nhiều chi phí cùng lúc */
    public function storeBatch(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('projects.manage');

        $data = $request->validate([
            'expense_ids'   => ['required', 'array', 'min:1'],
            'expense_ids.*' => ['required', 'integer'],
            'amounts'       => ['nullable', 'array'],
            'amounts.*'     => ['nullable', 'numeric', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'description'   => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $transfers = $this->service->transferBatch($project, $data);
        } catch (\Throwable $e) {
            return back()->with('error', 'Không thể kết chuyển: ' . $e->getMessage())->withInput();
        }

        $count = count($transfers);
        return back()->with('success', "Đã kết chuyển {$count} chi phí sang TK 154.");
    }

    /** Hủy kết chuyển */
    public function destroy(Request $request, Project $project, ProjectExpense $expense, ProjectExtraCostTransfer $transfer): RedirectResponse
    {
        abort_unless($expense->project_id === $project->id, 404);
        abort_unless($transfer->project_expense_id === $expense->id, 404);
        $this->authorize('projects.manage');

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->service->cancelTransfer($transfer, $data['cancel_reason']);
        } catch (\Throwable $e) {
            return back()->with('error', 'Không thể hủy kết chuyển: ' . $e->getMessage());
        }

        return back()->with('success', 'Đã hủy kết chuyển.');
    }
}
