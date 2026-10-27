<?php

namespace App\Actions;

use App\Enums\RunStatus;
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

    /**
     * No session picks it up until you resume it. A session working on it
     * now is stopped; the spec folder keeps its progress.
     */
    public function pause(Task $task, string $reason = 'Paused by you'): Task
    {
        if (! $task->status->isOpen() || $task->isPaused()) {
            throw ValidationException::withMessages(['status' => 'Only an open task that is not paused can be paused.']);
        }

        $stopped = $this->stopRuns($task);
        $task->fill(['paused_at' => now(), 'pause_reason' => $reason, 'claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null]);

        // Without a spec there is nothing to resume from, so it goes back to the queue (still paused).
        if ($task->spec_id === null) {
            $task->status = TaskStatus::Queued;
        }

        $task->save();
        Activity::record('task.paused', $reason.($stopped > 0 ? '; stopped its running session' : ''), $task);

        return $task;
    }

    /**
     * Picks up where the spec stands, at the next runner tick.
     */
    public function resume(Task $task): Task
    {
        if (! $task->isPaused() && ! $task->isBlocked()) {
            throw ValidationException::withMessages(['status' => 'Only a paused or blocked task can be resumed.']);
        }

        // Resuming a blocked task overrides the wait on its blocker.
        $task->fill(['paused_at' => null, 'pause_reason' => null, 'blocked_by_task_id' => null]);
        $task->save();
        Activity::record('task.resumed', 'Resumed', $task);

        return $task;
    }

    /**
     * Starts it over from wherever its spec stands: stops any session on it,
     * drops the claim and the attempt count, unpauses it and queues it.
     */
    public function restart(Task $task): Task
    {
        if ($task->status === TaskStatus::Done) {
            throw ValidationException::withMessages(['status' => 'A done task cannot be restarted.']);
        }

        $stopped = $this->stopRuns($task);
        $task->fill(['claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null, 'attempts' => 0, 'paused_at' => null, 'pause_reason' => null, 'blocked_by_task_id' => null]);

        return $this->move($task, TaskStatus::Queued, 'Restarted'.($stopped > 0 ? '; stopped its running session' : ''));
    }

    private function stopRuns(Task $task): int
    {
        return $task->runs()->where('status', RunStatus::Running)->update(['status' => RunStatus::Stopping]);
    }

    private function move(Task $task, TaskStatus $status, string $message): Task
    {
        $task->status = $status;
        $task->save();
        Activity::record('task.status', $message, $task);

        return $task;
    }
}
