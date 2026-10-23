<?php

namespace App\Actions;

use App\Enums\TaskPriority;
use App\Models\Activity;
use App\Models\Task;

final class UpdateTask
{
    public function handle(Task $task, string $title, ?string $body, ?string $ticketRef, TaskPriority $priority): Task
    {
        $task->fill(['title' => $title, 'body' => $body, 'ticket_ref' => $ticketRef, 'priority' => $priority]);

        if ($task->isDirty()) {
            $changed = implode(', ', array_map(fn (string $field): string => str_replace('_', ' ', $field), array_keys($task->getDirty())));
            $task->save();
            Activity::record('task.edited', "Edited {$changed}", $task);
        }

        return $task;
    }
}
