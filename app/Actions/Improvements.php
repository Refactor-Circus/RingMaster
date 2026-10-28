<?php

namespace App\Actions;

use App\Enums\ImprovementStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\Activity;
use App\Models\Improvement;
use App\Models\Run;
use App\Support\Improver;
use App\Support\Notifier;
use App\Support\Replays;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes to the factory, from proposal to adoption. The analyst proposes;
 * you accept (a Ringside change is queued as a task that builds it on a
 * branch, a RingMaster setting is yours to edit), evaluate (recent jobs are
 * replayed against the branch) and adopt or dismiss. Adopting never merges:
 * the improvement's PR is yours to merge like any other.
 */
final class Improvements
{
    public const array TARGETS = ['ringside', 'allowlist', 'config'];

    public function __construct(private readonly CreateTask $tasks, private readonly Replays $replays, private readonly Notifier $notifier) {}

    public function propose(string $session, string $title, string $target, string $rationale, string $evidence, string $change): Improvement
    {
        $analyst = Run::query()->where('session', trim($session))->where('kind', RunKind::Improve)->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->with('project')->first();

        if ($analyst === null) {
            throw ValidationException::withMessages(['session' => 'Only a running factory analyst session can propose improvements.']);
        }

        if (! in_array($target, self::TARGETS, true)) {
            throw ValidationException::withMessages(['target' => 'target is one of: '.implode(', ', self::TARGETS).'.']);
        }

        $max = max(1, (int) config('ring-master.improve.max_proposals', 3));

        if (Improvement::query()->where('created_at', '>=', $analyst->started_at)->where('workspace_id', $analyst->project->workspace_id)->count() >= $max) {
            throw ValidationException::withMessages(['title' => "This run already proposed {$max}."]);
        }

        $title = Str::limit(trim($title), 200);

        if (Improvement::query()->where('workspace_id', $analyst->project->workspace_id)->whereNot('status', ImprovementStatus::Dismissed)->whereRaw('lower(title) = ?', [Str::lower($title)])->exists()) {
            throw ValidationException::withMessages(['title' => "\"{$title}\" is already proposed."]);
        }

        $improvement = Improvement::query()->create([
            'workspace_id' => $analyst->project->workspace_id,
            'title' => $title,
            'target' => $target,
            'rationale' => trim($rationale),
            'evidence' => trim($evidence),
            'change' => trim($change),
            'status' => ImprovementStatus::Open,
        ]);

        Activity::record('improvement.proposed', "Factory improvement proposed (run #{$analyst->id}): {$title}");

        return $improvement;
    }

    /**
     * A Ringside change is queued as a task in the Ringside checkout; a
     * RingMaster setting is left for you to edit (adopt it once you did).
     */
    public function accept(Improvement $improvement): Improvement
    {
        $this->ensure($improvement, [ImprovementStatus::Open]);

        if ($improvement->target !== 'ringside') {
            return $this->adopt($improvement);
        }

        $ringside = Improver::ringside($improvement->workspace) ?? throw ValidationException::withMessages(['improvement' => 'This workspace has no Ringside checkout to build it in.']);
        $task = $this->tasks->handle($ringside, TaskKind::Idea, "Factory improvement: {$improvement->title}",
            "{$improvement->change}\n\nWhy: {$improvement->rationale}\n\nEvidence: {$improvement->evidence}\n\n"
            .'This is a change to the factory itself. Build it as a normal job with its own PR; RingMaster replays recent jobs against its branch before the user adopts it. Never merge it.',
            priority: TaskPriority::High);

        $improvement->update(['status' => ImprovementStatus::Building, 'task_id' => $task->id]);
        Activity::record('improvement.accepted', "Accepted: {$improvement->title}; building as task #{$task->id}");

        return $improvement;
    }

    /**
     * Queues replays of recent finished jobs against the improvement's branch.
     */
    public function evaluate(Improvement $improvement): Improvement
    {
        $this->ensure($improvement, [ImprovementStatus::Building, ImprovementStatus::Evaluated]);

        if ($improvement->branch === null) {
            throw ValidationException::withMessages(['improvement' => 'Its branch is not known yet: the task that builds it has not opened its PR.']);
        }

        $specs = $this->replays->candidates($improvement);

        if ($specs === []) {
            throw ValidationException::withMessages(['improvement' => 'No finished job with tasks in this workspace to replay.']);
        }

        $improvement->update(['status' => ImprovementStatus::Evaluating, 'results' => [
            'pending' => array_map(fn ($spec): array => ['project_id' => $spec->project_id, 'key' => $spec->key], $specs),
            'specs' => [],
            'budget_usd' => (float) config('ring-master.improve.budget_usd', 20),
        ]]);
        Activity::record('improvement.evaluating', "Evaluating {$improvement->title}: replaying ".count($specs).' job(s)');

        return $improvement;
    }

    public function adopt(Improvement $improvement): Improvement
    {
        $this->ensure($improvement, [ImprovementStatus::Open, ImprovementStatus::Building, ImprovementStatus::Evaluated]);
        $improvement->update(['status' => ImprovementStatus::Adopted, 'resolved_at' => now()]);
        Activity::record('improvement.adopted', "Adopted: {$improvement->title}");

        return $improvement;
    }

    public function dismiss(Improvement $improvement): Improvement
    {
        $this->ensure($improvement, [ImprovementStatus::Open, ImprovementStatus::Building, ImprovementStatus::Evaluated]);
        $improvement->update(['status' => ImprovementStatus::Dismissed, 'resolved_at' => now()]);

        return $improvement;
    }

    /**
     * Picks up the branch of an improvement's task once its spec has one. Called on each runner tick.
     */
    public function track(): void
    {
        foreach (Improvement::query()->where('status', ImprovementStatus::Building)->whereNull('branch')->whereNotNull('task_id')->with('task.spec')->get() as $improvement) {
            $spec = $improvement->task?->spec;
            $branch = $spec === null ? null : $spec->branch;

            if ($spec !== null && is_string($branch) && $branch !== '' && in_array($spec->status, ['in-review', 'merged', 'done'], true)) {
                $improvement->update(['branch' => $branch]);
                Activity::record('improvement.ready', "Ready to evaluate: {$improvement->title} on {$branch}");
                $this->notifier->send('A factory improvement is ready to evaluate', [$improvement->title], '/factory');
            }
        }
    }

    /**
     * @param  list<ImprovementStatus>  $statuses
     */
    private function ensure(Improvement $improvement, array $statuses): void
    {
        if (! in_array($improvement->status, $statuses, true)) {
            throw ValidationException::withMessages(['improvement' => "It is {$improvement->status->value}: that cannot be done now."]);
        }
    }
}
