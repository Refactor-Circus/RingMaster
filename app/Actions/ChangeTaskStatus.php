<?php

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

/**
 * The status changes you make by hand. Everything else follows the spec.
 */
final class ChangeTaskStatus
{
    public function cancel(Task $task): Task
    {
        if (! $task->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only an open task can be cancelled.']);
        }

        return $this->move($task, TaskStatus::Cancelled, 'Cancelled');
    }

    /**
     * Back into the queue, released from whoever had claimed it.
     */
    public function requeue(Task $task): Task
    {
        if (! in_array($task->status, [TaskStatus::Cancelled, TaskStatus::Failed], true)) {
            throw ValidationException::withMessages(['status' => 'Only a cancelled or failed task can be queued again.']);
        }

        $task->fill(['claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null]);

        return $this->move($task, TaskStatus::Queued, 'Queued again');
    }

    private function move(Task $task, TaskStatus $status, string $message): Task
    {
        $task->status = $status;
        $task->save();
        Activity::record('task.status', $message, $task);

        return $task;
    }
}
