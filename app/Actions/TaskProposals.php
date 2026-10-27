<?php

namespace App\Actions;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up jobs a session found its task needs, such as a fix in another
 * package. A session can only propose one: it runs once you accept it in
 * the Inbox. The task that needs it waits, blocked, and resumes by itself
 * once the follow-up is done (or its GitHub PR is merged).
 */
final class TaskProposals
{
    public function __construct(private readonly ClaimTasks $claims, private readonly Notifier $notifier) {}

    public function propose(int $taskId, string $session, string $folder, string $title, string $body, string $reason, bool $blocks = true): Task
    {
        // A session applying answers holds no claim; an unclaimed task is fair game for it.
        $task = Task::query()->with('project.workspace')->find($taskId);
        $task = $task !== null && $task->claimed_by === null && $task->status->isOpen() ? $task : $this->claims->held($taskId, $session);
        $project = $task->project->workspace->projects()->where('folder', $folder)->first();

        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project' => "No project named {$folder} in {$task->project->workspace->name}."]);
        }

        return DB::transaction(function () use ($task, $project, $title, $body, $reason, $blocks): Task {
            $proposal = Task::query()->create([
                'project_id' => $project->id,
                'kind' => TaskKind::Idea,
                'title' => Str::limit(trim($title), 250),
                'body' => trim($body),
                'priority' => TaskPriority::Normal,
                'status' => TaskStatus::Proposed,
                'proposal_reason' => Str::limit(trim($reason), 1990),
            ]);

            Activity::record('task.proposed', "Proposed by task #{$task->id}: ".trim($reason), $proposal);

            if ($blocks) {
                $task->update(['blocked_by_task_id' => $proposal->id]);
                Activity::record('task.blocked', "Waiting on proposed task #{$proposal->id} in {$project->folder}: {$proposal->title}", $task);
            }

            $this->notifier->send('A follow-up job is proposed', ["{$project->folder}: {$proposal->title}"], '/inbox');

            return $proposal;
        });
    }

    public function accept(Task $proposal): Task
    {
        $this->ensureProposed($proposal);
        $proposal->update(['status' => TaskStatus::Queued]);
        Activity::record('task.created', "Accepted and queued: {$proposal->title}", $proposal);

        return $proposal;
    }

    /**
     * Cancels it. Tasks that waited on it stay paused until you resume them.
     */
    public function dismiss(Task $proposal): Task
    {
        $this->ensureProposed($proposal);

        DB::transaction(function () use ($proposal): void {
            $proposal->update(['status' => TaskStatus::Cancelled]);
            Activity::record('task.cancelled', "Declined: {$proposal->title}", $proposal);

            foreach ($proposal->blocking as $task) {
                $task->update([
                    'blocked_by_task_id' => null,
                    'paused_at' => $task->paused_at ?? now(),
                    'pause_reason' => "You declined the follow-up it waited on (#{$proposal->id}: {$proposal->title}). Resume it when it can go on.",
                ]);
                Activity::record('task.paused', "Paused: you declined #{$proposal->id}", $task);
            }
        });

        return $proposal;
    }

    /**
     * Resumes tasks whose blocker is done, or whose blocker's PR is merged
     * (see PullRequests). Called on each runner tick.
     */
    public function unblockFinished(): int
    {
        $count = 0;

        foreach (Task::query()->whereNotNull('blocked_by_task_id')->with('blockedBy.spec', 'project')->get() as $task) {
            $blocker = $task->blockedBy;

            if ($blocker === null || ! $this->finished($blocker)) {
                continue;
            }

            $note = "Task #{$blocker->id} ({$blocker->project->folder}: {$blocker->title}), which this task waited on, is done. "
                ."Before going on, bring its change in: pull {$blocker->project->folder}'s base branch in {$blocker->project->path} (git -C <path> pull --ff-only). "
                // composer local:update regenerates composer.local.json from composer.json, dropping local-only dev packages such as Ringside.
                ."Sibling checkouts are symlinked, so that is usually enough; if this job's install needs refreshing, run COMPOSER=composer.local.json composer update {$blocker->project->name} where it installs it, never composer local:update.";

            $task->update(['blocked_by_task_id' => null, 'paused_at' => null, 'pause_reason' => null, 'resume_note' => $note]);
            Activity::record('task.resumed', "Resumed: #{$blocker->id} is done", $task);
            $this->notifier->send('A blocked task resumed', ["{$task->project->folder}: {$task->title}: #{$blocker->id} is done"], "/tasks/{$task->id}");
            $count++;
        }

        return $count;
    }

    private function finished(Task $blocker): bool
    {
        return $blocker->status === TaskStatus::Done || $blocker->spec?->pr_merged_at !== null;
    }

    private function ensureProposed(Task $task): void
    {
        if ($task->status !== TaskStatus::Proposed) {
            throw ValidationException::withMessages(['status' => 'Only a proposed task can be accepted or declined.']);
        }
    }
}
