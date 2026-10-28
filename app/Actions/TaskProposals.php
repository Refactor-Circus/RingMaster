<?php

namespace App\Actions;

use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Support\ActionLinks;
use App\Support\Notifier;
use App\Support\Precedents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Follow-up jobs a session found its task needs, such as a fix in another
 * package. A session can only propose one: it runs once you accept it in
 * the Inbox. The task that needs it waits, blocked, and resumes by itself
 * once the follow-up is done (or its GitHub PR is merged). A follow-up can
 * instead wait for the proposing task, as when a change breaks a package
 * that uses it: the fix there starts once this task's PR is merged.
 */
final class TaskProposals
{
    /** How alike (0 to 1) a title must be to an open task's in the same project to count as the same job. */
    public const float DUPLICATE_SIMILARITY = 0.3;

    public function __construct(private readonly ClaimTasks $claims, private readonly TaskDependencies $dependencies, private readonly Notifier $notifier) {}

    /**
     * A follow-up an open task in that project already covers (a campaign's,
     * another session's) is not created again: the existing task is linked
     * the same way and returned, not recently created. notDuplicate overrides
     * that for a change that only looks alike.
     */
    public function propose(int $taskId, string $session, string $folder, string $title, string $body, string $reason, bool $blocks = true, bool $waitsForThis = false, bool $notDuplicate = false): Task
    {
        // A session applying answers holds no claim; an unclaimed task is fair game for it.
        $task = Task::query()->with('project.workspace')->find($taskId);
        $task = $task !== null && $task->claimed_by === null && $task->status->isOpen() ? $task : $this->claims->held($taskId, $session);
        $project = $task->project->workspace->projects()->where('folder', $folder)->first();

        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project' => "No project named {$folder} in {$task->project->workspace->name}."]);
        }

        $existing = $notDuplicate ? null : $this->duplicate($project, $title, $task);

        if ($existing !== null) {
            if ($waitsForThis) {
                $this->dependencies->add($existing, $task, 'it needs this change merged first');
            } elseif ($blocks) {
                $this->dependencies->add($task, $existing, 'already covers the proposed follow-up');
            }

            Activity::record('task.progress', "Task #{$task->id} proposed \"".Str::limit(trim($title), 120)."\"; task #{$existing->id} already covers it", $existing);

            return $existing;
        }

        return DB::transaction(function () use ($task, $project, $title, $body, $reason, $blocks, $waitsForThis): Task {
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

            if ($waitsForThis) {
                $this->dependencies->add($proposal, $task, 'it needs this change merged first');
            } elseif ($blocks) {
                $this->dependencies->add($task, $proposal, 'proposed follow-up');
            }

            $this->notifier->send('A follow-up job is proposed', ["{$project->folder}: {$proposal->title}"], '/inbox', [
                ActionLinks::make('proposal.accept', $proposal->id, 'Accept and queue'),
                ActionLinks::make('proposal.decline', $proposal->id, 'Decline'),
            ]);

            return $proposal;
        });
    }

    /**
     * A job the portfolio planner suggests: proposed like a follow-up, with
     * no task waiting on it. The same title as an open task in that project
     * is refused, so a planner run never queues a duplicate.
     */
    public function suggest(string $session, string $folder, string $title, string $body, string $reason): Task
    {
        $session = trim($session);
        $planning = Run::query()->where('session', $session)->where('kind', RunKind::Plan)->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->with('project')->first();

        if ($planning === null) {
            throw ValidationException::withMessages(['session' => 'Only a running planner session can suggest jobs.']);
        }

        $project = $planning->project->workspace->projects()->where('folder', $folder)->first();

        if (! $project instanceof Project) {
            throw ValidationException::withMessages(['project' => "No project named {$folder} in {$planning->project->workspace->name}."]);
        }

        $title = Str::limit(trim($title), 250);

        $existing = $this->duplicate($project, $title);

        if ($existing !== null) {
            throw ValidationException::withMessages(['title' => "{$folder} already has an open task like it: #{$existing->id} \"{$existing->title}\"."]);
        }

        $proposal = Task::query()->create([
            'project_id' => $project->id,
            'kind' => TaskKind::Idea,
            'title' => $title,
            'body' => trim($body),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Proposed,
            'proposal_reason' => Str::limit('Planner: '.trim($reason), 1990),
        ]);

        Activity::record('task.proposed', "Suggested by the planner (run #{$planning->id}): ".trim($reason), $proposal);

        return $proposal;
    }

    /**
     * The open or proposed task in the project whose title reads most like
     * this one, when alike enough. The project's own name is left out, since
     * campaign titles carry it.
     */
    public function duplicate(Project $project, string $title, ?Task $except = null): ?Task
    {
        $folder = Precedents::terms($project->folder);
        $terms = array_values(array_diff(Precedents::terms($title), $folder));
        $best = null;
        $score = self::DUPLICATE_SIMILARITY;

        foreach ($project->tasks()->whereIn('status', [...TaskStatus::open(), TaskStatus::Proposed])->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))->get() as $open) {
            $similarity = Precedents::similarity($terms, array_values(array_diff(Precedents::terms($open->title), $folder)));

            if ($similarity >= $score) {
                [$best, $score] = [$open, $similarity];
            }
        }

        return $best;
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

            foreach ($proposal->dependents as $task) {
                $this->dependencies->remove($task, $proposal);
                $task->update([
                    'paused_at' => $task->paused_at ?? now(),
                    'pause_reason' => "You declined the follow-up it waited on (#{$proposal->id}: {$proposal->title}). Resume it when it can go on.",
                ]);
                Activity::record('task.paused', "Paused: you declined #{$proposal->id}", $task);
            }
        });

        return $proposal;
    }

    private function ensureProposed(Task $task): void
    {
        if ($task->status !== TaskStatus::Proposed) {
            throw ValidationException::withMessages(['status' => 'Only a proposed task can be accepted or declined.']);
        }
    }
}
