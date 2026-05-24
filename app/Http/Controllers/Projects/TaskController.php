<?php

namespace App\Http\Controllers\Projects;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $maxOrder = $project->tasks()->max('sort_order') ?? 0;

        $project->tasks()->create([
            ...$data,
            'status'     => TaskStatus::Todo,
            'sort_order' => $maxOrder + 1,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Đã thêm công việc.');
    }

    public function update(Request $request, Project $project, ProjectTask $task): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority'    => ['nullable', 'in:low,medium,high'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $task->update($data);

        return back()->with('success', 'Đã cập nhật công việc.');
    }

    public function updateStatus(Request $request, Project $project, ProjectTask $task): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);
        $request->validate(['status' => ['required', 'string']]);
        $newStatus = TaskStatus::from($request->status);

        $this->service->updateTaskStatus($task, $newStatus);

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    public function destroy(Project $project, ProjectTask $task): RedirectResponse
    {
        abort_unless($task->project_id === $project->id, 404);
        $task->delete();

        return back()->with('success', 'Đã xóa công việc.');
    }
}
