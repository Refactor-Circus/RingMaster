<?php

namespace App\Support;

use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use App\Models\Activity;
use App\Models\Chore;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Process;

/**
 * Tidies each project's git checkout once its work is merged. On its own it
 * removes clean worktrees and deletes local branches whose commits are on
 * the base branch (merged, or the same changes squashed in). Branches on
 * GitHub are another matter: deleting them is raised as a chore and runs
 * only once you approve it. A dirty worktree, an unmerged branch and the
 * branch a checkout is on are never touched.
 */
final class Janitor
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return array<string, array{worktrees: list<string>, branches: list<string>, remote: list<string>, skipped: list<string>}> per project folder
     */
    public function run(bool $dryRun = false): array
    {
        $report = [];

        foreach ($this->projects() as $project) {
            $report[$project->folder] = $this->project($project, $dryRun);
        }

        return $report;
    }

    /**
     * @return array{worktrees: list<string>, branches: list<string>, remote: list<string>, skipped: list<string>}
     */
    public function project(Project $project, bool $dryRun = false): array
    {
        $report = ['worktrees' => [], 'branches' => [], 'remote' => [], 'skipped' => []];
        $origin = $this->git($project, 'config', '--get', 'remote.origin.url');

        if ($origin !== null) {
            $this->git($project, 'fetch', '--prune', '--quiet', 'origin');
        }

        $base = $this->base($project, $origin !== null);

        if ($base === null) {
            return $report;
        }

        foreach ($this->worktrees($project) as $path => $branch) {
            if ($branch === null || ! $this->merged($project, $branch, $base)) {
                continue;
            }

            if (trim((string) $this->git($project, '-C', $path, 'status', '--porcelain')) !== '') {
                $report['skipped'][] = "worktree {$path}: uncommitted changes";

                continue;
            }

            if ($dryRun || $this->git($project, 'worktree', 'remove', $path) !== null) {
                $report['worktrees'][] = $path;
            }
        }

        $this->git($project, 'worktree', 'prune');
        $current = trim((string) $this->git($project, 'branch', '--show-current'));
        $checkedOut = array_filter($this->worktrees($project));
        $baseName = str_contains($base, '/') ? substr($base, strpos($base, '/') + 1) : $base;

        foreach ($this->refs($project, 'refs/heads') as $branch) {
            if ($branch === $current || $branch === $baseName || in_array($branch, $checkedOut, true) || ! $this->merged($project, $branch, $base)) {
                continue;
            }

            if ($dryRun || $this->git($project, 'branch', '-D', $branch) !== null) {
                $report['branches'][] = $branch;
            }
        }

        if ($origin !== null) {
            foreach ($this->refs($project, 'refs/remotes/origin') as $ref) {
                if (in_array($ref, ['origin', 'origin/HEAD', $base], true) || ! $this->merged($project, $ref, $base)) {
                    continue;
                }

                $report['remote'][] = substr($ref, strlen('origin/'));
            }

            if (! $dryRun) {
                $this->raise($project, $report['remote'], (string) $origin);
            }
        }

        if (! $dryRun && ($report['worktrees'] !== [] || $report['branches'] !== [])) {
            Activity::record('janitor.cleaned', "{$project->folder}: removed ".count($report['worktrees']).' worktree(s) and '.count($report['branches']).' merged branch(es)');
        }

        return $report;
    }

    /**
     * Deletes the branches the chore lists on GitHub, once you approved it.
     */
    public function deleteRemote(Chore $chore): string
    {
        /** @var list<string> $branches */
        $branches = $chore->payload['branches'] ?? [];
        $result = Process::path($chore->project->path)->timeout(120)->run(['git', 'push', 'origin', '--delete', ...$branches]);

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'git push --delete failed');
        }

        return 'Deleted '.count($branches).' branch(es) on '.$chore->project->folder.'\'s remote.';
    }

    /**
     * One open chore per project for its merged remote branches; a changed
     * set replaces the old one, and none left dismisses it.
     *
     * @param  list<string>  $branches
     */
    private function raise(Project $project, array $branches, string $origin): void
    {
        sort($branches);
        $key = $branches === [] ? null : 'remote-branches:'.substr(sha1(implode("\n", $branches)), 0, 16);

        Chore::query()->where('project_id', $project->id)->where('kind', ChoreKind::RemoteBranches)->where('status', ChoreStatus::Open)
            ->when($key !== null, fn ($query) => $query->where('key', '!=', $key))
            ->update(['status' => ChoreStatus::Dismissed, 'result' => 'Superseded by a newer check.', 'resolved_at' => now()]);

        if ($key === null || ! str_contains($origin, 'github.com') || Chore::query()->where('project_id', $project->id)->where('key', $key)->exists()) {
            return;
        }

        $chore = Chore::query()->create([
            'project_id' => $project->id,
            'kind' => ChoreKind::RemoteBranches,
            'key' => $key,
            'title' => count($branches).' merged '.(count($branches) === 1 ? 'branch' : 'branches').' left on GitHub',
            'detail' => implode("\n", $branches),
            'payload' => ['branches' => $branches],
        ]);

        $this->notifier->send('Merged branches to clean up', ["{$project->folder}: ".count($branches).' on GitHub'], '/inbox', [
            ActionLinks::make('chore.approve', $chore->id, 'Delete them on GitHub'),
            ActionLinks::make('chore.dismiss', $chore->id, 'Leave them'),
        ]);
    }

    /**
     * @return iterable<Project>
     */
    private function projects(): iterable
    {
        /** @var list<string> $skip */
        $skip = config('ring-master.janitor.skip', []);

        return Project::query()
            ->whereIn('workspace_id', Workspace::query()->where('enabled', true)->select('id'))
            ->whereNotIn('folder', $skip)
            ->orderBy('folder')
            ->get()
            ->filter(fn (Project $project): bool => is_dir($project->path.'/.git'));
    }

    /**
     * The branch merged work lands on: origin's default branch, else main or master.
     */
    private function base(Project $project, bool $hasOrigin): ?string
    {
        if ($hasOrigin) {
            $head = trim((string) $this->git($project, 'symbolic-ref', '--short', 'refs/remotes/origin/HEAD'));

            if ($head !== '') {
                return $head;
            }
        }

        foreach ($hasOrigin ? ['origin/main', 'origin/master'] : ['main', 'master'] as $candidate) {
            if ($this->git($project, 'rev-parse', '--verify', '--quiet', $candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether every commit of $ref is on $base: merged, or (for squash and
     * rebase merges) the same changes applied there.
     */
    private function merged(Project $project, string $ref, string $base): bool
    {
        if ($this->git($project, 'merge-base', '--is-ancestor', $ref, $base) !== null) {
            return true;
        }

        $cherry = $this->git($project, 'cherry', $base, $ref);

        return $cherry !== null && ! str_contains("\n".$cherry, "\n+");
    }

    /**
     * Linked worktrees (not the main checkout): path => branch, or null when detached.
     *
     * @return array<string, string|null>
     */
    private function worktrees(Project $project): array
    {
        $worktrees = [];
        $path = null;

        foreach (explode("\n", (string) $this->git($project, 'worktree', 'list', '--porcelain')) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $path = substr($line, 9);
                $worktrees[$path] = null;
            } elseif ($path !== null && str_starts_with($line, 'branch refs/heads/')) {
                $worktrees[$path] = substr($line, strlen('branch refs/heads/'));
            }
        }

        // The first entry is the checkout itself.
        array_shift($worktrees);

        return $worktrees;
    }

    /**
     * @return list<string>
     */
    private function refs(Project $project, string $prefix): array
    {
        return array_values(array_filter(explode("\n", trim((string) $this->git($project, 'for-each-ref', '--format=%(refname:short)', $prefix)))));
    }

    /**
     * Runs git in the project; its output, or null when it failed.
     */
    private function git(Project $project, string ...$args): ?string
    {
        $result = Process::path($project->path)->timeout(60)->run(['git', ...$args]);

        return $result->successful() ? $result->output() : null;
    }
}
