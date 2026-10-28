<?php

namespace App\Support;

use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use App\Enums\TaskStatus;
use App\Models\Chore;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\Process;

/**
 * Triages each GitHub project's open Dependabot PRs. One with green checks
 * becomes a chore to merge it; one with failing checks, a chore to queue a
 * task that fixes it. Nothing is merged or queued until you approve, and a
 * chore whose PR closed or changed state is dismissed.
 */
final class Dependabot
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return int chores raised
     */
    public function triage(): int
    {
        $raised = 0;

        /** @var list<string> $skip */
        $skip = config('ring-master.janitor.skip', []);

        $projects = Project::query()
            ->whereIn('workspace_id', Workspace::query()->where('enabled', true)->select('id'))
            ->whereNotIn('folder', $skip)
            ->get()
            ->filter(fn (Project $project): bool => is_dir($project->path.'/.git'));

        foreach ($projects as $project) {
            $raised += $this->project($project);
        }

        return $raised;
    }

    public function project(Project $project): int
    {
        $origin = Process::path($project->path)->run(['git', 'config', '--get', 'remote.origin.url']);

        if (! $origin->successful() || ! str_contains($origin->output(), 'github.com')) {
            return 0;
        }

        $list = Process::path($project->path)->timeout(60)->run(['gh', 'pr', 'list', '--author', 'app/dependabot', '--state', 'open', '--json', 'number,title,url,headRefName']);
        $prs = json_decode(trim($list->output()), true);

        if (! $list->successful() || ! is_array($prs)) {
            return 0;
        }

        $keep = [];
        $raised = 0;

        foreach ($prs as $pr) {
            if (! is_array($pr) || ! is_string($pr['url'] ?? null)) {
                continue;
            }

            $verdict = $this->checks($pr['url']);

            if ($verdict === null) {
                continue;
            }

            [$kind, $failing] = $verdict;
            $key = $kind->value.':'.$pr['url'].($failing === [] ? '' : ':'.substr(sha1(implode("\n", $failing)), 0, 12));
            $keep[] = $key;

            if (Chore::query()->where('project_id', $project->id)->where('key', $key)->exists()) {
                continue;
            }

            // A task already fixing this PR covers new failing runs too: no second chore, so no second task.
            if ($kind === ChoreKind::DependabotFix && self::fixing($project, $pr['url']) !== null) {
                continue;
            }

            $title = (string) ($pr['title'] ?? 'Dependabot PR');
            $chore = Chore::query()->create([
                'project_id' => $project->id,
                'kind' => $kind,
                'key' => $key,
                'title' => $kind === ChoreKind::DependabotMerge ? "Green: {$title}" : "Failing checks: {$title}",
                'detail' => $failing === [] ? $pr['url'] : $pr['url']."\n".implode("\n", $failing),
                'payload' => ['url' => $pr['url'], 'title' => $title, 'branch' => (string) ($pr['headRefName'] ?? ''), 'failing' => $failing],
            ]);
            $this->notifier->send('A Dependabot PR needs you', ["{$project->folder}: {$title}"], '/inbox', [
                ActionLinks::make('chore.approve', $chore->id, $kind->action()),
                ActionLinks::make('chore.dismiss', $chore->id, 'Leave it'),
            ]);
            $raised++;
        }

        Chore::query()->where('project_id', $project->id)->whereIn('kind', [ChoreKind::DependabotMerge, ChoreKind::DependabotFix])->where('status', ChoreStatus::Open)
            ->whereNotIn('key', $keep)
            ->update(['status' => ChoreStatus::Dismissed, 'result' => 'The PR closed or its checks changed.', 'resolved_at' => now()]);

        return $raised;
    }

    /**
     * Merges an approved green PR with a merge commit, or squashed when the
     * repository allows only that.
     */
    public function merge(Chore $chore): string
    {
        $url = (string) ($chore->payload['url'] ?? '');

        foreach (['--merge', '--squash'] as $method) {
            $result = Process::path($chore->project->path)->timeout(120)->run(['gh', 'pr', 'merge', $url, $method, '--delete-branch']);

            if ($result->successful()) {
                return "Merged {$url}.";
            }
        }

        throw new \RuntimeException(trim($result->errorOutput()) ?: "gh pr merge {$url} failed");
    }

    /**
     * The chore a PR's checks call for, with the failing ones; null while
     * they are pending or none ran.
     *
     * @return array{ChoreKind, list<string>}|null
     */
    private function checks(string $url): ?array
    {
        // gh exits non-zero while checks fail or are pending; the JSON is still printed.
        $checks = json_decode(trim(Process::timeout(30)->run(['gh', 'pr', 'checks', $url, '--json', 'name,bucket,link'])->output()), true);

        if (! is_array($checks) || $checks === []) {
            return null;
        }

        $buckets = array_column($checks, 'bucket');

        if (in_array('pending', $buckets, true)) {
            return null;
        }

        $failing = array_values(array_map(
            fn (array $check): string => "- {$check['name']}: {$check['link']}",
            array_filter($checks, fn (mixed $check): bool => is_array($check) && in_array($check['bucket'] ?? null, ['fail', 'cancel'], true)),
        ));

        return [$failing === [] ? ChoreKind::DependabotMerge : ChoreKind::DependabotFix, $failing];
    }

    /**
     * The open task already fixing a Dependabot PR, if any.
     */
    public static function fixing(Project $project, string $url): ?Task
    {
        return Task::query()->where('project_id', $project->id)->whereIn('status', [...TaskStatus::open(), TaskStatus::Proposed])
            ->where('body', 'like', '%'.$url.' %')->oldest('id')->first();
    }
}
