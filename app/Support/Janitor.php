<?php

namespace App\Support;

use App\Enums\ChoreKind;
use App\Enums\ChoreStatus;
use App\Enums\RunStatus;
use App\Models\Activity;
use App\Models\Chore;
use App\Models\Project;
use App\Models\Run;
use App\Models\Workspace;
use App\Runner\Runner;
use Illuminate\Support\Facades\Process;
use RefactorCircus\Ringside\Specs\Locator;

/**
 * Tidies each project's git checkout once its work is merged. On its own it
 * brings the checkout up to the base branch (jobs build against their
 * siblings' local checkouts, so a stale one breaks them), removes clean
 * worktrees and deletes local branches whose commits are on the base branch
 * (merged, or the same changes squashed in). Branches on
 * GitHub are another matter: deleting them is raised as a chore and runs
 * only once you approve it. A dirty worktree, an unmerged branch and the
 * branch a checkout is on are never touched, and neither is a job worktree
 * whose spec has yet to reach the checkout: it would vanish from the board.
 */
final class Janitor
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return array<string, array{updated: list<string>, worktrees: list<string>, branches: list<string>, remote: list<string>, skipped: list<string>}> per project folder
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
     * @return array{updated: list<string>, worktrees: list<string>, branches: list<string>, remote: list<string>, skipped: list<string>}
     */
    public function project(Project $project, bool $dryRun = false): array
    {
        $report = ['updated' => [], 'worktrees' => [], 'branches' => [], 'remote' => [], 'skipped' => []];
        $origin = $this->git($project, 'config', '--get', 'remote.origin.url');

        if ($origin !== null) {
            $this->git($project, 'fetch', '--prune', '--quiet', 'origin');
        }

        $base = $this->base($project, $origin !== null);

        if ($base === null) {
            return $report;
        }

        if ((bool) config('ring-master.janitor.pull', true)) {
            [$updated, $skipped] = $this->sync($project, $base, $dryRun);
            array_push($report['updated'], ...$updated);
            array_push($report['skipped'], ...$skipped);
        }

        $specs = array_flip(Locator::worktrees($project->ringside()));

        foreach ($this->worktrees($project) as $path => $branch) {
            if ($branch === null || ! $this->merged($project, $branch, $base)) {
                continue;
            }

            if (isset($specs[$path]) && $this->git($project, 'ls-files', '--error-unmatch', '--', Locator::relative($project->ringside()).'/'.$specs[$path].'/meta.yml') === null) {
                $report['skipped'][] = "worktree {$path}: its spec is not on the checkout yet";

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

        if (! $dryRun && ($report['updated'] !== [] || $report['worktrees'] !== [] || $report['branches'] !== [])) {
            Activity::record('janitor.cleaned', "{$project->folder}: ".implode('; ', [...$report['updated'], 'removed '.count($report['worktrees']).' worktree(s) and '.count($report['branches']).' merged branch(es)']));
        }

        return $report;
    }

    /**
     * Brings the checkout up to $base: fast-forwards the base branch when it
     * is behind, and leaves a branch whose work is all merged for the base
     * branch (its own cleanup then deletes it). A checkout with uncommitted
     * tracked changes, commits of its own, a detached HEAD, or a session
     * working in it is left as it is. Call it after a fetch.
     *
     * @return array{0: list<string>, 1: list<string>} what changed, what was left and why
     */
    public function sync(Project $project, string $base, bool $dryRun = false): array
    {
        $busy = Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get()
            ->contains(fn (Run $run): bool => Runner::overlaps($project->path, [$run->project->path]));
        $current = trim((string) $this->git($project, 'branch', '--show-current'));
        $baseName = str_contains($base, '/') ? substr($base, strpos($base, '/') + 1) : $base;

        $why = match (true) {
            $busy => 'a session is working in it',
            $current === '' => 'detached HEAD',
            trim((string) $this->git($project, 'status', '--porcelain', '--untracked-files=no')) !== '' => 'uncommitted changes',
            $current !== $baseName && ! $this->merged($project, $current, $base) => "on {$current}, which has unmerged work",
            $current === $baseName && trim((string) $this->git($project, 'rev-list', '--count', "{$base}..HEAD")) !== '0' => "{$baseName} has commits of its own",
            default => null,
        };

        // Only a checkout that is actually behind is worth a word.
        if ($why !== null) {
            return [[], trim((string) $this->git($project, 'rev-list', '--count', "HEAD..{$base}")) !== '0' ? ["checkout not updated: {$why}"] : []];
        }

        $updated = [];

        if ($current !== $baseName) {
            if (! $dryRun && $this->git($project, 'switch', $baseName) === null) {
                return [[], ["checkout not updated: could not switch from {$current} to {$baseName}"]];
            }

            $updated[] = "left {$current} (merged) for {$baseName}";
        }

        $behind = (int) trim((string) $this->git($project, 'rev-list', '--count', "{$baseName}..{$base}"));

        if ($behind > 0) {
            $cleared = $dryRun ? 0 : $this->clearMerged($project, $baseName, $base);

            if ($cleared > 0) {
                $updated[] = "dropped {$cleared} untracked file(s) the merge brings unchanged";
            }

            if (! $dryRun && $this->git($project, 'merge', '--ff-only', '--quiet', $base) === null) {
                return [$updated, ["checkout not updated: {$baseName} could not fast-forward to {$base}"]];
            }

            $updated[] = "fast-forwarded {$baseName} {$behind} commit(s)";
        }

        return [$updated, []];
    }

    /**
     * Deletes untracked files that the commits from $baseName to $base add
     * with the very same content, which git would refuse to overwrite: a
     * spec folder left on the checkout from before its job committed it.
     * A file that differs is kept, and the fast-forward reports it.
     */
    private function clearMerged(Project $project, string $baseName, string $base): int
    {
        $cleared = 0;

        foreach (array_filter(explode("\n", (string) $this->git($project, 'diff', '--name-only', '--diff-filter=A', $baseName, $base))) as $file) {
            $path = $project->path.'/'.$file;

            if (! is_file($path) || $this->git($project, 'ls-files', '--error-unmatch', '--', $file) !== null) {
                continue;
            }

            if (trim((string) $this->git($project, 'hash-object', '--', $file)) === trim((string) $this->git($project, 'rev-parse', "{$base}:{$file}")) && @unlink($path)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Fetches the project and brings its checkout up to the base branch, as
     * right after one of its PRs is merged.
     *
     * @return list<string> what changed
     */
    public function update(Project $project): array
    {
        if (! is_dir($project->path.'/.git') || ! (bool) config('ring-master.janitor.pull', true)) {
            return [];
        }

        $origin = $this->git($project, 'config', '--get', 'remote.origin.url');

        if ($origin !== null) {
            $this->git($project, 'fetch', '--prune', '--quiet', 'origin');
        }

        $base = $this->base($project, $origin !== null);
        [$updated] = $base === null ? [[]] : $this->sync($project, $base);

        if ($updated !== []) {
            Activity::record('janitor.cleaned', "{$project->folder}: ".implode('; ', $updated));
        }

        return $updated;
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
