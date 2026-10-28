<?php

namespace App\Support;

use App\Enums\RunKind;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Run;
use App\Models\Signal;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * The portfolio planner: a read-only session over a whole workspace that
 * reads each package's roadmap, standards feedback and recent post-mortems
 * and suggests jobs with suggest-task. Suggestions wait in the Inbox like
 * any proposed job; nothing runs them before you accept one. It runs when
 * you ask (the Runs page) or every planner.every_days days when set.
 */
final class Planner
{
    /** Workspace ids waiting for a plan run you asked for. */
    public const string REQUESTED = 'ring-master.planner.requested';

    public static function request(): void
    {
        Cache::forever(self::REQUESTED, Workspace::query()->where('enabled', true)->pluck('id')->all());
    }

    /**
     * Workspace roots due a plan run: asked for, or the last one is older than every_days.
     *
     * @return list<Project>
     */
    public function due(): array
    {
        /** @var list<int> $requested */
        $requested = Cache::get(self::REQUESTED, []);
        $every = config('ring-master.planner.every_days');
        $due = [];

        foreach (Project::query()->where('is_workspace', true)->whereHas('workspace', fn ($query) => $query->where('enabled', true))->get() as $root) {
            $last = Run::query()->where('project_id', $root->id)->where('kind', RunKind::Plan)->latest('started_at')->value('started_at');
            $scheduled = is_numeric($every) && (int) $every > 0 && ($last === null || $last->lt(now()->subDays((int) $every)));

            if (in_array($root->workspace_id, $requested, true) || $scheduled) {
                $due[] = $root;
            }
        }

        return $due;
    }

    public function started(Project $root): void
    {
        /** @var list<int> $requested */
        $requested = Cache::get(self::REQUESTED, []);
        Cache::forever(self::REQUESTED, array_values(array_diff($requested, [$root->workspace_id])));
    }

    public function prompt(Run $run): string
    {
        $workspace = $run->project->workspace;
        $open = Task::query()
            ->with('project')
            ->whereIn('status', [...TaskStatus::open(), TaskStatus::Proposed])
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->get()
            ->map(fn (Task $task): string => "- {$task->project->folder}: {$task->title}")
            ->implode("\n");
        $max = max(1, (int) config('ring-master.planner.max_suggestions', 5));
        $folders = $workspace->projects()->where('is_workspace', false)->orderBy('folder')->pluck('folder')->implode(', ');
        $signals = Signal::query()
            ->with('project')
            ->where('status', 'open')
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->latest('seen_at')
            ->limit(40)
            ->get()
            ->map(fn (Signal $signal): string => "- {$signal->project->folder} ({$signal->kind->label()}): {$signal->title}".($signal->url !== null ? " {$signal->url}" : '').($signal->detail !== null ? " [{$signal->detail}]" : ''))
            ->implode("\n") ?: '- none';

        return <<<PROMPT
        You are RingMaster's portfolio planner for the workspace at {$workspace->path} (packages: {$folders}). Session name: {$run->session}.

        Read, for each package: docs/agent/product/ (mission, roadmap, tech stack), docs/agent/standards-feedback.md, and the post-mortem.md of its newest finished specs under docs/agent/specs/. Also read the workspace's own docs/agent/product/ if it has one.

        Signals from GitHub (open issues, checks that fail now and then on the base branch, Dependabot PRs nobody dealt with):
        {$signals}

        Then suggest at most {$max} jobs, one suggest-task call each, that move a roadmap forward, fix a standard that feedback shows keeps being broken, follow up a post-mortem, or deal with a signal (cite its URL in the reason). Each needs: project (the package folder), title, body (written as the task for a fresh session: what to change, where, and how to tell it is done) and reason (why now, with the file you read it in).

        Never suggest something already open or proposed:
        {$open}

        Only read: do not edit files, run commands or start jobs. The user accepts or declines each suggestion in the Inbox. End with one line per suggestion you made.
        PROMPT;
    }
}
