<?php

namespace App\Support;

use App\Enums\AttentionKind;
use App\Enums\ImprovementStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Run;
use App\Models\Spec;
use App\Models\Workspace;
use App\Runner\RunLog;
use Illuminate\Support\Facades\Cache;

/**
 * The factory analyst: a read-only session over a workspace that reads the
 * evidence of recent jobs (failed runs by class, refused commands, CI
 * auto-fixes, escalations, review rounds, post-mortems) next to Ringside's
 * own skills, and proposes changes to the factory with propose-improvement.
 * They wait on the Factory page; nothing changes until you accept one.
 */
final class Improver
{
    /** Workspace ids waiting for an analyst run you asked for. */
    public const string REQUESTED = 'ring-master.improver.requested';

    public static function request(): void
    {
        Cache::forever(self::REQUESTED, Workspace::query()->where('enabled', true)->pluck('id')->all());
    }

    /**
     * Whether an analyst run you asked for is still waiting to start.
     */
    public static function requested(): bool
    {
        return Cache::get(self::REQUESTED, []) !== [];
    }

    /**
     * Workspace roots due an analyst run: asked for, or the last one is older than every_days.
     *
     * @return list<Project>
     */
    public function due(): array
    {
        /** @var list<int> $requested */
        $requested = Cache::get(self::REQUESTED, []);
        $every = config('ring-master.improve.every_days');
        $due = [];

        foreach (Project::query()->where('is_workspace', true)->whereHas('workspace', fn ($query) => $query->where('enabled', true))->get() as $root) {
            $last = Run::query()->where('project_id', $root->id)->where('kind', RunKind::Improve)->latest('started_at')->value('started_at');
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
        $max = max(1, (int) config('ring-master.improve.max_proposals', 3));
        $ringside = self::ringside($workspace);
        $skills = $ringside === null ? 'vendor/refactor-circus/ringside/resources' : $ringside->path.'/resources';
        $evidence = $this->evidence($workspace);
        $open = Improvement::query()->where('workspace_id', $workspace->id)->whereNotIn('status', [ImprovementStatus::Dismissed])->pluck('title')->map(fn (string $title): string => "- {$title}")->implode("\n") ?: '- none';

        return <<<PROMPT
        You are RingMaster's factory analyst for the workspace at {$workspace->path}. Session name: {$run->session}.

        The factory is Ringside: its skills and workflow live in {$skills} (skills/workflow/*.md, workflows/ring-master-factory.js), its defaults in {$skills}/../src/Config.php, and RingMaster's runner allowlist in {$this->configPath()}.

        Evidence from the last 30 days:
        {$evidence}

        Read the post-mortems and standards feedback listed, and the skill text behind each problem. Then propose at most {$max} changes to the factory, one propose-improvement call each, that would have prevented a failure, a refused command, an escalation or a wasted round. Each needs: title, target (ringside for a skill, workflow or default; allowlist for runner.allowed_tools; config for another RingMaster setting), rationale, evidence (the runs, specs or files it rests on) and change (exactly what to edit, as a task for a fresh session).

        Do not propose what is already proposed:
        {$open}

        Only read: do not edit files, run commands or start jobs. The user accepts each proposal on the Factory page, and accepted Ringside changes are replayed against recent jobs before they are adopted. End with one line per proposal.
        PROMPT;
    }

    /**
     * The Ringside checkout in a workspace, when it is one of its projects.
     */
    public static function ringside(Workspace $workspace): ?Project
    {
        foreach ($workspace->projects()->where('is_workspace', false)->get() as $project) {
            if (DependencyOrder::composerName($project) === 'refactor-circus/ringside') {
                return $project;
            }
        }

        return null;
    }

    private function configPath(): string
    {
        return config_path('ring-master.php');
    }

    private function evidence(Workspace $workspace): string
    {
        $projects = $workspace->projects()->pluck('id')->all();
        $runs = Run::query()->whereIn('project_id', $projects)->where('started_at', '>=', now()->subDays(30))->get();
        $failures = $runs->where('status', RunStatus::Failed)->groupBy(fn (Run $run): string => $run->failure?->label() ?? 'unknown')->map->count();
        $refused = $runs->pluck('refused')->flatten()->filter(fn (mixed $call): bool => is_string($call))->groupBy(fn (string $call): string => RunLog::suggestion($call))->map->count()->sortDesc()->take(10);
        $specs = Spec::query()->with('project')->whereIn('project_id', $projects)->where('spec_updated_at', '>=', now()->subDays(30))->get();
        $escalations = AttentionItem::query()->with('spec.project')->where('kind', AttentionKind::Escalation)->whereIn('spec_id', $specs->pluck('id'))->latest()->limit(10)->get();
        $autofixes = Activity::query()->where('type', 'ci.autofix')->whereIn('spec_id', $specs->pluck('id'))->count();

        $lines = [
            'Runs: '.$runs->count().', failed by class: '.($failures->isEmpty() ? 'none' : $failures->map(fn (int $n, string $class): string => "{$class} ×{$n}")->implode(', ')),
            'Refused commands (allowlist entry or fix): '.($refused->isEmpty() ? 'none' : $refused->map(fn (int $n, string $fix): string => "{$fix} ×{$n}")->implode('; ')),
            "CI auto-fixes: {$autofixes}",
            'Review rounds per job: '.($specs->isEmpty() ? 'none' : $specs->map(fn (Spec $spec): string => "{$spec->project->folder}/{$spec->key} ".count($spec->rounds))->implode(', ')),
            'Escalations:',
            ...$escalations->map(fn (AttentionItem $item): string => "- {$item->spec->project->folder}/{$item->spec->key} ({$item->source}): ".mb_strimwidth(str_replace("\n", ' ', (string) $item->evidence), 0, 300, '…'))->all(),
            'Post-mortems and feedback to read:',
            ...$specs->filter(fn (Spec $spec): bool => in_array($spec->dir.'/post-mortem.md', $spec->files, true))->map(fn (Spec $spec): string => "- {$spec->project->path}/{$spec->dir}/post-mortem.md")->all(),
            ...$workspace->projects()->get()->map(fn (Project $project): string => "- {$project->path}/docs/agent/standards-feedback.md")->all(),
        ];

        return implode("\n", $lines);
    }
}
