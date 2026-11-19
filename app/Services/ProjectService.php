<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectTask;
use RuntimeException;

class ProjectService
{
    private const ALLOWED_TRANSITIONS = [
        'planning'    => ['in_progress', 'cancelled'],
        'in_progress' => ['on_hold', 'completed', 'cancelled'],
        'on_hold'     => ['in_progress', 'cancelled'],
        'completed'   => [],
        'cancelled'   => [],
    ];

    public function transition(Project $project, ProjectStatus $newStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$project->status->value] ?? [];

        if (!in_array($newStatus->value, $allowed)) {
            throw new RuntimeException(
                "Không thể chuyển từ \"{$project->status->label()}\" sang \"{$newStatus->label()}\"."
            );
        }

        $data = ['status' => $newStatus];

        if ($newStatus === ProjectStatus::Completed) {
            $data['actual_end_date'] = now()->toDateString();
        }

        $project->update($data);
    }

    /**
     * Sửa trạng thái thủ công (bỏ qua luồng ALLOWED_TRANSITIONS) — dùng khi cần điều chỉnh,
     * vd mở lại dự án đã Hoàn thành/Đã hủy. Không sinh bút toán; bắt buộc lý do + ghi activity log.
     */
    public function overrideStatus(Project $project, ProjectStatus $newStatus, string $reason): void
    {
        $oldStatus = $project->status;

        if ($oldStatus === $newStatus) {
            throw new RuntimeException("Dự án đang ở trạng thái \"{$newStatus->label()}\".");
        }

        $data = ['status' => $newStatus];

        if ($newStatus === ProjectStatus::Completed && ! $project->actual_end_date) {
            $data['actual_end_date'] = now()->toDateString();
        } elseif ($oldStatus === ProjectStatus::Completed) {
            $data['actual_end_date'] = null;
        }

        $project->update($data);

        activity()
            ->performedOn($project)
            ->causedBy(auth()->user())
            ->withProperties([
                'old_status' => $oldStatus->value,
                'new_status' => $newStatus->value,
                'reason'     => $reason,
            ])
            ->log("Sửa trạng thái dự án: {$oldStatus->label()} → {$newStatus->label()}");
    }

    public function updateTaskStatus(ProjectTask $task, TaskStatus $newStatus): void
    {
        $data = ['status' => $newStatus];

        if ($newStatus === TaskStatus::Done && !$task->completed_at) {
            $data['completed_at'] = now();
        } elseif ($newStatus !== TaskStatus::Done) {
            $data['completed_at'] = null;
        }

        $task->update($data);
    }

    public function progressPercent(Project $project): int
    {
        $tasks = $project->tasks;
        if ($tasks->isEmpty()) {
            return 0;
        }

        $done = $tasks->where('status', TaskStatus::Done)->count();
        return (int) round($done / $tasks->count() * 100);
    }
}
