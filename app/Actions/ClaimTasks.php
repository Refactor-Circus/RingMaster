<?php

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * How a Claude session takes work from the queue and hands it back. A claim
 * is a lease: it runs out unless the session sends heartbeats, and an
 * expired claim on a task without a spec returns the task to the queue.
 * A paused or blocked task is never claimable.
 */
final class ClaimTasks
{
    /**
     * Spec statuses a stalled task is resumed from once its lease ran out:
     * the interactive stages. A building spec's factory workflow can run
     * longer than a lease without heartbeats, so it counts as stalled only
     * when neither its spec folder nor its worktree changed for
     * claims.building_stall_minutes.
     */
    public const array RESUMABLE = ['ticketed', 'shaping', 'written', 'approved'];

    /** Statuses whose stall is judged by file activity instead of heartbeats. */
    public const array BUILDING = ['building', 'in-progress'];

    public function __construct(private readonly ScanWorkspaces $scan, private readonly Notifier $notifier) {}

    /**
     * The task a session in this folder should do next, without claiming it.
     */
    public function next(?string $path): ?Task
    {
        return $this->claimableQuery()
            ->whereHas('project', fn (Builder $query) => $query->within($path))
            ->with('project')
            ->inQueueOrder()
            ->first();
    }

    /**
     * Claimable tasks across every enabled workspace, in pickup order.
     *
     * @return Collection<int, Task>
     */
    public function candidates(int $limit): Collection
    {
        return $this->claimableQuery()->with('project')->inQueueOrder()->limit($limit)->get();
    }

    public function claimable(int $taskId): bool
    {
        return $this->claimableQuery()->whereKey($taskId)->exists();
    }

    public function claim(int $taskId, string $session): Task
    {
        return DB::transaction(function () use ($taskId, $session): Task {
            $resumed = Task::query()->whereKey($taskId)->whereNotNull('spec_id')->exists();

            $claimed = $this->claimableQuery()->whereKey($taskId)->update([
                'status' => TaskStatus::Claimed,
                'claimed_by' => $session,
                'claimed_at' => now(),
                'lease_expires_at' => $this->leaseEnd(),
            ]);

            if ($claimed === 0) {
                throw ValidationException::withMessages(['task_id' => "Task {$taskId} is not waiting in the queue (it may be claimed by another session)."]);
            }

            $task = Task::query()->with('project')->findOrFail($taskId);
            Activity::record('task.claimed', ($resumed ? 'Resumed by ' : 'Claimed by ').$session, $task);

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
     * A task with a spec is paused instead: the session stopped on something
     * a fresh session would hit again, so it waits until you resume it.
     */
    public function release(int $taskId, string $session, string $reason, bool $failed = false): Task
    {
        $task = $this->held($taskId, $session);
        $paused = ! $failed && $task->spec_id !== null;
        $task->update([
            'status' => $failed ? TaskStatus::Failed : ($task->spec_id === null ? TaskStatus::Queued : $task->status),
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'resume_note' => null,
            ...($paused ? ['paused_at' => now(), 'pause_reason' => Str::limit(trim($reason), 1990)] : []),
        ]);

        Activity::record($failed ? 'task.failed' : ($paused ? 'task.paused' : 'task.status'), ($failed ? 'Failed: ' : ($paused ? 'Paused: ' : 'Released: ')).trim($reason), $task);

        if ($failed || $paused) {
            $this->notifier->send($failed ? 'A task failed' : 'A task is paused', ["{$task->project->folder}: {$task->title}: ".Str::limit(trim($reason), 160)], "/tasks/{$task->id}");
        }

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
    private function claimableQuery(): Builder
    {
        return Task::query()
            ->whereNull('paused_at')
            ->whereNull('blocked_by_task_id')
            ->whereHas('project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->where(fn (Builder $query) => $query
                ->where('status', TaskStatus::Queued)
                // Resumed by you: it follows its spec but nobody holds it, so it starts again now.
                ->orWhere(fn (Builder $query) => $query
                    ->whereIn('status', [TaskStatus::Running, TaskStatus::Claimed])
                    ->whereNotNull('spec_id')
                    ->whereNull('claimed_by')
                    ->whereHas('spec', fn (Builder $query) => $query->whereIn('status', [...self::RESUMABLE, ...self::BUILDING])))
                ->orWhere(fn (Builder $query) => $query->where('status', TaskStatus::Claimed)->whereNull('spec_id')->where('lease_expires_at', '<', now()))
                // In review and its PR merged: a session records the merge and runs the post-mortem.
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', TaskStatus::InReview)
                    ->where(fn (Builder $query) => $query->whereNull('claimed_by')->orWhere('lease_expires_at', '<', now()))
                    ->whereHas('spec', fn (Builder $query) => $query->where('status', 'in-review')->whereNotNull('pr_merged_at')))
                // Stalled: a session took it, linked a spec in an interactive stage, then stopped sending heartbeats.
                ->orWhere(fn (Builder $query) => $query
                    ->whereIn('status', [TaskStatus::Running, TaskStatus::Claimed])
                    ->whereNotNull('spec_id')
                    ->whereNotNull('claimed_by')
                    ->where('lease_expires_at', '<', now())
                    ->whereHas('spec', fn (Builder $query) => $query
                        ->whereIn('status', self::RESUMABLE)
                        ->orWhere(fn (Builder $query) => $query
                            ->whereIn('status', self::BUILDING)
                            ->where('activity_at', '<', now()->subMinutes((int) config('ring-master.claims.building_stall_minutes', 60)))))));
    }

    public function held(int $taskId, string $session): Task
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
