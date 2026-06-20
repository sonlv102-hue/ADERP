<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Services\ProjectWipCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectWipCorrectionController extends Controller
{
    public function __construct(private ProjectWipCorrectionService $service) {}

    // ─── Preview endpoints (JSON) ────────────────────────────────────────────

    public function previewCancel(Project $project, ProjectWipEntry $wip): JsonResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        try {
            $wip->load('journalEntry.lines', 'project');
            $preview = $this->service->previewCancel($wip);
            return response()->json($preview);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function previewTransfer(Request $request, Project $project, ProjectWipEntry $wip): JsonResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $request->validate(['target_project_id' => ['required', 'exists:projects,id']]);
        $target = Project::findOrFail($request->target_project_id);

        try {
            $wip->load('project');
            $preview = $this->service->previewTransfer($wip, $target);
            return response()->json($preview);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function previewReclass(Request $request, Project $project, ProjectWipEntry $wip): JsonResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $request->validate(['target_account_code' => ['required', 'string', 'max:20']]);

        try {
            $wip->load('project');
            $preview = $this->service->previewReclass($wip, $request->target_account_code);
            return response()->json($preview);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // ─── Execute endpoints (redirect) ────────────────────────────────────────

    public function cancel(Request $request, Project $project, ProjectWipEntry $wip): RedirectResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $wip->load('journalEntry.lines', 'project');
            $this->service->cancelEntry($wip, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy chi phí dở dang và tạo bút toán đảo.');
    }

    public function transfer(Request $request, Project $project, ProjectWipEntry $wip): RedirectResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $data = $request->validate([
            'target_project_id' => ['required', 'exists:projects,id'],
            'reason'            => ['required', 'string', 'max:500'],
        ]);

        $target = Project::findOrFail($data['target_project_id']);

        try {
            $wip->load('project');
            $this->service->transferToProject($wip, $target, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã chuyển chi phí dở dang sang dự án {$target->code}.");
    }

    public function reclass(Request $request, Project $project, ProjectWipEntry $wip): RedirectResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $data = $request->validate([
            'target_account_code' => ['required', 'string', 'max:20'],
            'reason'              => ['required', 'string', 'max:500'],
        ]);

        try {
            $wip->load('project');
            $this->service->reclassAccount($wip, $data['target_account_code'], $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã điều chỉnh tài khoản chi phí sang {$data['target_account_code']}.");
    }

    // Lịch sử xử lý của 1 WIP entry (JSON)
    public function history(Project $project, ProjectWipEntry $wip): JsonResponse
    {
        $this->authorize('project.wip.adjust');
        abort_unless($wip->project_id === $project->id, 404);

        $logs = $wip->correctionLogs()
            ->with(['performedByUser', 'correctionJe'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($log) => [
                'id'            => $log->id,
                'action_type'   => $log->action_type,
                'action_label'  => $log->actionLabel(),
                'from_project'  => $log->from_project_id,
                'to_project'    => $log->to_project_id,
                'from_account'  => $log->from_account,
                'to_account'    => $log->to_account,
                'amount'        => $log->amount,
                'reason'        => $log->reason,
                'performed_by'  => $log->performedByUser?->name,
                'performed_at'  => $log->created_at->format('d/m/Y H:i'),
                'je_code'       => $log->correctionJe?->code,
            ]);

        return response()->json(['logs' => $logs]);
    }
}
