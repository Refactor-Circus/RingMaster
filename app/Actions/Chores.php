<?php

namespace App\Actions;

use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use App\Enums\TaskKind;
use App\Models\Activity;
use App\Models\Chore;
use App\Support\Dependabot;
use App\Support\Janitor;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Your answer to a chore: approve runs it (delete the branches on GitHub,
 * merge the PR, queue the fix), dismiss leaves things as they are, retry
 * puts a failed one back in the Inbox.
 */
final class Chores
{
    public function __construct(
        private readonly Janitor $janitor,
        private readonly Dependabot $dependabot,
        private readonly CreateTask $tasks,
    ) {}

    public function approve(Chore $chore): Chore
    {
        $this->ensureOpen($chore);

        try {
            $result = match ($chore->kind) {
                ChoreKind::RemoteBranches => $this->janitor->deleteRemote($chore),
                ChoreKind::DependabotMerge => $this->dependabot->merge($chore),
                ChoreKind::DependabotFix => $this->queueFix($chore),
            };
            $chore->update(['status' => ChoreStatus::Done, 'result' => $result, 'resolved_at' => now()]);
        } catch (Throwable $e) {
            $chore->update(['status' => ChoreStatus::Failed, 'result' => $e->getMessage(), 'resolved_at' => now()]);
        }

        Activity::record('chore.'.$chore->status->value, "{$chore->project->folder}: {$chore->title}: ".(string) $chore->result);

        return $chore;
    }

    public function dismiss(Chore $chore): Chore
    {
        $this->ensureOpen($chore);
        $chore->update(['status' => ChoreStatus::Dismissed, 'result' => 'You left it.', 'resolved_at' => now()]);

        return $chore;
    }

    public function retry(Chore $chore): Chore
    {
        if ($chore->status !== ChoreStatus::Failed) {
            throw ValidationException::withMessages(['chore' => 'Only a failed chore can be retried.']);
        }

        $chore->update(['status' => ChoreStatus::Open, 'result' => null, 'resolved_at' => null]);

        return $chore;
    }

    private function queueFix(Chore $chore): string
    {
        $url = (string) ($chore->payload['url'] ?? '');
        $branch = (string) ($chore->payload['branch'] ?? '');
        /** @var list<string> $failing */
        $failing = $chore->payload['failing'] ?? [];

        $open = Dependabot::fixing($chore->project, $url);

        if ($open !== null) {
            return "Task #{$open->id} already fixes this PR; nothing new queued.";
        }

        $task = $this->tasks->handle($chore->project, TaskKind::Idea, 'Fix Dependabot PR: '.(string) ($chore->payload['title'] ?? $url),
            "Dependabot's PR {$url} (branch {$branch}) has failing checks:\n".implode("\n", $failing)
            ."\n\nWork in a worktree of your own, never the main checkout: `git worktree add ../{$chore->project->folder}--dependabot {$branch}` (after `git fetch origin {$branch}`), and remove it when you are done. Read the failing job logs (gh run view <run> --log-failed), fix what the update broke on that branch, and push to it so the PR goes green. Do not merge it.");

        return "Queued task #{$task->id}.";
    }

    private function ensureOpen(Chore $chore): void
    {
        if ($chore->status !== ChoreStatus::Open) {
            throw ValidationException::withMessages(['chore' => 'This one was already dealt with.']);
        }
    }
}
