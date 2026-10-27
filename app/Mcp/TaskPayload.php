<?php

namespace App\Mcp;

use App\Enums\TaskKind;
use App\Models\Task;

/**
 * What a Claude session needs to work on a task.
 */
final class TaskPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Task $task): array
    {
        $task->loadMissing(['project', 'spec']);

        return [
            'id' => $task->id,
            'kind' => $task->kind->value,
            'title' => $task->title,
            'body' => $task->body,
            'ticket_ref' => $task->ticket_ref,
            'priority' => $task->priority->label(),
            'status' => $task->status->value,
            'claimed_by' => $task->claimed_by,
            'lease_expires_at' => $task->lease_expires_at?->toIso8601String(),
            'project' => ['folder' => $task->project->folder, 'path' => $task->project->path, 'is_workspace' => $task->project->is_workspace],
            'spec' => $task->spec === null ? null : ['key' => $task->spec->key, 'dir' => $task->spec->dir, 'status' => $task->spec->status],
            'ship_input' => self::shipInput($task),
            'resume_note' => $task->resume_note,
            'blocked_by' => $task->blocked_by_task_id,
        ];
    }

    /**
     * The argument for /ring-master-ship, run in the project's folder.
     */
    private static function shipInput(Task $task): string
    {
        return match (true) {
            $task->spec !== null => $task->spec->key,
            $task->kind === TaskKind::Ticket && $task->ticket_ref !== null => $task->ticket_ref,
            default => trim($task->title."\n\n".$task->body),
        };
    }
}
