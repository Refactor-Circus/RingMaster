<?php

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How a Claude session takes work from the queue and hands it back. A claim
 * is a lease: it runs out unless the session sends heartbeats, and an
 * expired claim on a task without a spec returns the task to the queue.
 */
final class ClaimTasks
{
    public function __construct(private readonly ScanWorkspaces $scan) {}

    /**
     * The task a session in this folder should do next, without claiming it.
     */
    public function next(?string $path): ?Task
    {
        return $this->claimable()
            ->whereHas('project', fn (Builder $query) => $query->within($path))
            ->with('project')
            ->inQueueOrder()
            ->first();
    }

    public function claim(int $taskId, string $session): Task
    {
        return DB::transaction(function () use ($taskId, $session): Task {
            $claimed = $this->claimable()->whereKey($taskId)->update([
                'status' => TaskStatus::Claimed,
                'claimed_by' => $session,
                'claimed_at' => now(),
                'lease_expires_at' => $this->leaseEnd(),
            ]);

            if ($claimed === 0) {
                throw ValidationException::withMessages(['task_id' => "Task {$taskId} is not waiting in the queue (it may be claimed by another session)."]);
            }

            $task = Task::query()->with('project')->findOrFail($taskId);
            Activity::record('task.claimed', "Claimed by {$session}", $task);

            return $task;
        });
    }

    public function heartbeat(int $taskId, string $session, ?string $message = null): Task
    {
        $task = $this->held($taskId, $session);
        $task->update(['lease_expires_at' => $this->leaseEnd()]);

        if ($message !== null && trim($message) !== '') {
            Activity::record('task.progress', trim($message), $task);
        }

        return $task;
    }

    /**
     * Records the spec folder the session made for this task and rescans,
     * so the task follows the spec from now on.
     */
    public function linkSpec(int $taskId, string $session, string $spec): Task
    {
        $task = $this->held($taskId, $session);
        $key = basename(rtrim($spec, '/'));

        $task->update(['spec_key' => $key, 'status' => TaskStatus::Running, 'lease_expires_at' => $this->leaseEnd()]);
        Activity::record('task.progress', "Working on spec {$key}", $task);

        $this->scan->scan($task->project->workspace);

        return $task->refresh();
    }

    /**
     * Back to the queue for another session, or failed when it cannot be done.
     */
    public function release(int $taskId, string $session, string $reason, bool $failed = false): Task
    {
        $task = $this->held($taskId, $session);
        $task->update([
            'status' => $failed ? TaskStatus::Failed : ($task->spec_id === null ? TaskStatus::Queued : $task->status),
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
        ]);

        Activity::record('task.status', ($failed ? 'Failed: ' : 'Released: ').trim($reason), $task);

        return $task;
    }

    /**
     * Returns tasks whose claim ran out before a spec existed to the queue.
     */
    public function expire(): int
    {
        $expired = Task::query()
            ->where('status', TaskStatus::Claimed)
            ->whereNull('spec_id')
            ->where('lease_expires_at', '<', now())
            ->get();

        foreach ($expired as $task) {
            $task->update(['status' => TaskStatus::Queued, 'claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null]);
            Activity::record('task.status', 'Claim expired; back in the queue', $task);
        }

        return $expired->count();
    }

    /**
     * @return Builder<Task>
     */
    private function claimable(): Builder
    {
        return Task::query()
            ->whereHas('project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->where(fn (Builder $query) => $query
                ->where('status', TaskStatus::Queued)
                ->orWhere(fn (Builder $query) => $query->where('status', TaskStatus::Claimed)->whereNull('spec_id')->where('lease_expires_at', '<', now())));
    }

    private function held(int $taskId, string $session): Task
    {
        $task = Task::query()->with('project.workspace')->find($taskId);

        if ($task === null || $task->claimed_by !== $session || ! $task->status->isOpen()) {
            throw ValidationException::withMessages(['task_id' => "Task {$taskId} is not claimed by {$session}."]);
        }

        return $task;
    }

    private function leaseEnd(): CarbonImmutable
    {
        return now()->addSeconds((int) config('ring-master.claims.lease_seconds', 900));
    }
}
