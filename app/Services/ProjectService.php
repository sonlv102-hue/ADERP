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
