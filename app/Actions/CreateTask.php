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
    public function __construct(private readonly TaskDependencies $dependencies) {}

    /**
     * Every task starts in the queue. A spec task is linked to its spec from
     * the start; it follows the spec's status once a session claims it. One
     * that depends on other tasks waits in the queue until they are done.
     *
     * @param  list<Task>  $dependsOn
     */
    public function handle(Project $project, TaskKind $kind, string $title, ?string $body = null, ?string $ticketRef = null, ?Spec $spec = null, TaskPriority $priority = TaskPriority::Normal, array $dependsOn = []): Task
    {
        return DB::transaction(function () use ($project, $kind, $title, $body, $ticketRef, $spec, $priority, $dependsOn): Task {
            $task = Task::query()->create([
                'project_id' => $project->id,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'ticket_ref' => $kind === TaskKind::Ticket ? $ticketRef : null,
                'spec_id' => $spec?->id,
                'spec_key' => $spec?->key,
                'priority' => $priority,
                'status' => TaskStatus::Queued,
            ]);

            Activity::record('task.created', "Queued {$kind->label()}: {$title}", $task);

            foreach ($dependsOn as $dependency) {
                $this->dependencies->add($task, $dependency);
            }

            return $task;
        });
    }
}
