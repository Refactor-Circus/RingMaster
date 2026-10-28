<?php

namespace App\Support;

use App\Enums\SignalKind;
use App\Models\Project;
use App\Models\Signal;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * Collects what is happening on GitHub around each project, for the planner
 * to suggest jobs from: open issues, checks on the base branch that pass and
 * fail by turns (flaky), and Dependabot PRs open for over a week. A signal
 * not seen on a pass is resolved. Read-only: it never changes GitHub.
 */
final class Signals
{
    /**
     * @return int open signals after the pass
     */
    public function collect(): int
    {
        /** @var list<string> $skip */
        $skip = config('ring-master.janitor.skip', []);

        $projects = Project::query()
            ->whereIn('workspace_id', Workspace::query()->where('enabled', true)->select('id'))
            ->where('is_workspace', false)
            ->whereNotIn('folder', $skip)
            ->get()
            ->filter(fn (Project $project): bool => is_dir($project->path.'/.git'));

        foreach ($projects as $project) {
            $this->project($project);
        }

        return Signal::query()->where('status', 'open')->count();
    }

    public function project(Project $project): void
    {
        $origin = Process::path($project->path)->run(['git', 'config', '--get', 'remote.origin.url']);

        if (! $origin->successful() || ! str_contains($origin->output(), 'github.com')) {
            return;
        }

        $passes = [
            [SignalKind::Issue, $this->issues($project)],
            [SignalKind::Flaky, $this->flaky($project)],
            [SignalKind::StaleDependabot, $this->staleDependabot($project)],
        ];

        foreach ($passes as [$kind, $seen]) {
            // A gh call that failed says nothing about what is gone.
            if ($seen !== null) {
                Signal::query()->where('project_id', $project->id)->where('kind', $kind)->where('status', 'open')->whereNotIn('key', $seen)->update(['status' => 'resolved']);
            }
        }
    }

    /**
     * @return list<string>|null keys seen, or null when gh failed
     */
    private function issues(Project $project): ?array
    {
        $issues = $this->gh($project, ['issue', 'list', '--state', 'open', '--limit', '30', '--json', 'number,title,url,labels']);

        if ($issues === null) {
            return null;
        }

        $keys = [];

        foreach ($issues as $issue) {
            if (! is_array($issue) || ! isset($issue['number'])) {
                continue;
            }

            $labels = implode(', ', array_filter(array_map(fn (mixed $label): ?string => is_array($label) && is_string($label['name'] ?? null) ? $label['name'] : null, is_array($issue['labels'] ?? null) ? $issue['labels'] : [])));
            $keys[] = $this->upsert($project, SignalKind::Issue, 'issue:'.$issue['number'], '#'.$issue['number'].' '.(string) ($issue['title'] ?? ''), $issue['url'] ?? null, $labels === '' ? null : "labels: {$labels}");
        }

        return $keys;
    }

    /**
     * Workflows on the default branch with both passing and failing runs among the recent ones.
     *
     * @return list<string>|null
     */
    private function flaky(Project $project): ?array
    {
        $runs = $this->gh($project, ['run', 'list', '--limit', '40', '--json', 'workflowName,conclusion,headBranch,url,event']);

        if ($runs === null) {
            return null;
        }
        $base = trim((string) Process::path($project->path)->timeout(30)->run(['gh', 'repo', 'view', '--json', 'defaultBranchRef', '--jq', '.defaultBranchRef.name'])->output()) ?: 'main';
        $byWorkflow = [];

        foreach ($runs as $run) {
            if (is_array($run) && ($run['headBranch'] ?? null) === $base && in_array($run['conclusion'] ?? null, ['success', 'failure'], true)) {
                $byWorkflow[(string) ($run['workflowName'] ?? 'workflow')][] = $run;
            }
        }

        $keys = [];

        foreach ($byWorkflow as $workflow => $list) {
            $failed = array_values(array_filter($list, fn (array $run): bool => $run['conclusion'] === 'failure'));

            // Flaky: it fails now and then but not always, and the latest is not a plain red build.
            if ($failed !== [] && count($failed) < count($list) && count($list) >= 4 && $list[0]['conclusion'] === 'success') {
                $keys[] = $this->upsert($project, SignalKind::Flaky, 'flaky:'.$workflow, "{$workflow} fails now and then on {$base}", $failed[0]['url'] ?? null, count($failed).' of the last '.count($list)." runs on {$base} failed");
            }
        }

        return $keys;
    }

    /**
     * @return list<string>|null
     */
    private function staleDependabot(Project $project): ?array
    {
        $prs = $this->gh($project, ['pr', 'list', '--author', 'app/dependabot', '--state', 'open', '--json', 'number,title,url,createdAt']);

        if ($prs === null) {
            return null;
        }

        $keys = [];

        foreach ($prs as $pr) {
            if (is_array($pr) && is_string($pr['createdAt'] ?? null) && CarbonImmutable::parse($pr['createdAt'])->lt(now()->subWeek())) {
                $keys[] = $this->upsert($project, SignalKind::StaleDependabot, 'dependabot:'.$pr['number'], (string) ($pr['title'] ?? 'Dependabot PR'), $pr['url'] ?? null, 'open since '.CarbonImmutable::parse($pr['createdAt'])->toDateString());
            }
        }

        return $keys;
    }

    private function upsert(Project $project, SignalKind $kind, string $key, string $title, mixed $url, ?string $detail): string
    {
        Signal::query()->updateOrCreate(['project_id' => $project->id, 'key' => $key], [
            'kind' => $kind,
            'title' => mb_strimwidth($title, 0, 250, '…'),
            'url' => is_string($url) ? $url : null,
            'detail' => $detail,
            'status' => 'open',
            'seen_at' => now(),
        ]);

        return $key;
    }

    /**
     * @param  list<string>  $args
     * @return list<mixed>|null
     */
    private function gh(Project $project, array $args): ?array
    {
        $result = Process::path($project->path)->timeout(60)->run(['gh', ...$args]);
        $data = json_decode(trim($result->output()), true);

        return $result->successful() && is_array($data) ? array_values($data) : null;
    }
}
