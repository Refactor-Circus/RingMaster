<?php

namespace App\Actions;

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Support\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The job graph: which tasks wait on which, across every project of a
 * workspace. A task waits until each task it depends on is done or has its
 * PR merged, then resumes by itself with a note telling the session to bring
 * those changes in first. Cycles are refused, so the queue always drains.
 */
final class TaskDependencies
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Makes $task wait on $dependsOn.
     */
    public function add(Task $task, Task $dependsOn, ?string $why = null): void
    {
        if ($task->id === $dependsOn->id) {
            throw ValidationException::withMessages(['depends_on' => 'A task cannot wait on itself.']);
        }

        if ($task->project->workspace_id !== $dependsOn->project->workspace_id) {
            throw ValidationException::withMessages(['depends_on' => 'A task can only wait on a task in the same workspace.']);
        }

        if ($this->finished($dependsOn)) {
            throw ValidationException::withMessages(['depends_on' => "Task #{$dependsOn->id} is already done."]);
        }

        if ($this->reaches($dependsOn, $task)) {
            throw ValidationException::withMessages(['depends_on' => "Task #{$dependsOn->id} already waits on #{$task->id}, directly or through others: that would be a cycle."]);
        }

        if ($task->dependencies()->whereKey($dependsOn->id)->exists()) {
            return;
        }

        $task->dependencies()->attach($dependsOn->id);
        Activity::record('task.blocked', "Waiting on #{$dependsOn->id} in {$dependsOn->project->folder}: {$dependsOn->title}".($why === null ? '' : " ({$why})"), $task);
    }

    public function remove(Task $task, Task $dependsOn): void
    {
        if ($task->dependencies()->detach($dependsOn->id) > 0) {
            Activity::record('task.unblocked', "No longer waits on #{$dependsOn->id}", $task);
        }
    }

    /**
     * Drops every dependency of $task, as when you resume or restart it by hand.
     */
    public function clear(Task $task): void
    {
        $task->dependencies()->detach();
    }

    /**
     * Resumes tasks whose every dependency is finished, dropping those rows.
     * Rows stay until all are finished, so the resume note names each one.
     * Called on each runner tick.
     *
     * @return int tasks resumed
     */
    public function unblockFinished(): int
    {
        $resumed = 0;

        foreach (Task::query()->whereHas('dependencies')->with('dependencies.spec', 'dependencies.project', 'project')->get() as $task) {
            $done = $task->dependencies;

            if (! $done->every(fn (Task $dependency): bool => $this->finished($dependency))) {
                continue;
            }

            $task->dependencies()->detach();

            $task->update(['paused_at' => null, 'pause_reason' => null, 'resume_note' => $this->note($done)]);
            Activity::record('task.resumed', 'Resumed: '.$this->names($done).($done->count() === 1 ? ' is' : ' are').' done', $task);
            $this->notifier->send('A blocked task resumed', ["{$task->project->folder}: {$task->title}"], "/tasks/{$task->id}");
            $resumed++;
        }

        return $resumed;
    }

    public function finished(Task $task): bool
    {
        return $task->status === TaskStatus::Done || $task->spec?->pr_merged_at !== null;
    }

    /**
     * Whether $from waits on $to, directly or through other tasks.
     */
    private function reaches(Task $from, Task $to): bool
    {
        $seen = [];
        $frontier = [$from->id];

        while ($frontier !== []) {
            $next = Task::query()->whereKey($frontier)->with('dependencies:id')->get()
                ->flatMap(fn (Task $task) => $task->dependencies->modelKeys())
                ->unique()
                ->reject(fn (int $id): bool => isset($seen[$id]))
                ->values()
                ->all();

            if (in_array($to->id, $next, true)) {
                return true;
            }

            foreach ($next as $id) {
                $seen[$id] = true;
            }

            $frontier = $next;
        }

        return false;
    }

    /**
     * @param  Collection<int, Task>  $done
     */
    private function note(Collection $done): string
    {
        $lines = $done->map(fn (Task $task): string => "#{$task->id} ({$task->project->folder}: {$task->title}): pull {$task->project->folder}'s base branch in {$task->project->path} (git -C <path> pull --ff-only).")->all();

        return 'The tasks this one waited on are done. Before going on, bring their changes in: '.implode(' ', $lines).' '
            // composer local:update regenerates composer.local.json from composer.json, dropping local-only dev packages such as Ringside.
            .'Sibling checkouts are symlinked, so that is usually enough; if this job\'s install needs refreshing, run COMPOSER=composer.local.json composer update '.$done->map(fn (Task $task): string => $task->project->name)->implode(' ').' where it installs them, never composer local:update.';
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function names(Collection $tasks): string
    {
        return $tasks->map(fn (Task $task): string => "#{$task->id}")->implode(', ');
    }
}
