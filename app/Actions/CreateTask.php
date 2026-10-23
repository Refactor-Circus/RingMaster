<?php

namespace App\Actions;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final class CreateTask
{
    /**
     * A spec task starts linked to that spec; any other waits in the queue
     * until Claude picks it up and a spec appears for it.
     */
    public function handle(Project $project, TaskKind $kind, string $title, ?string $body = null, ?string $ticketRef = null, ?Spec $spec = null, TaskPriority $priority = TaskPriority::Normal): Task
    {
        return DB::transaction(function () use ($project, $kind, $title, $body, $ticketRef, $spec, $priority): Task {
            $task = Task::query()->create([
                'project_id' => $project->id,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'ticket_ref' => $kind === TaskKind::Ticket ? $ticketRef : null,
                'spec_id' => $spec?->id,
                'spec_key' => $spec?->key,
                'priority' => $priority,
                'status' => $spec !== null ? (TaskStatus::fromSpec($spec->status) ?? TaskStatus::Queued) : TaskStatus::Queued,
            ]);

            Activity::record('task.created', "Queued {$kind->label()}: {$title}", $task);

            return $task;
        });
    }
}
