<?php

namespace App\Support;

use App\Enums\AttentionKind;
use App\Enums\ImprovementStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\Activity;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Run;
use App\Models\Spec;
use App\Models\Task;
use App\Runner\Runner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Evaluates an improvement by replaying recent finished jobs against its
 * Ringside branch. Each replay gets a throwaway checkout of the package at
 * the commit the job started from, with the job's spec and tasks copied in
 * (tasks unticked), Ringside installed from the improvement's branch, and
 * the factory run in replay mode: it builds and reviews, never pushes or
 * opens a PR, and writes replay.json. The result sits next to how the job
 * went the first time. Everything under storage/app/private/replays/<run>
 * is removed afterwards.
 */
final class Replays
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Recent finished jobs in the workspace's packages (not Ringside itself) to replay.
     *
     * @return list<Spec>
     */
    public function candidates(Improvement $improvement): array
    {
        $ringside = Improver::ringside($improvement->workspace);

        return array_values(Spec::query()->with('project')
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $improvement->workspace_id)->where('is_workspace', false)->when($ringside !== null, fn ($query) => $query->whereKeyNot($ringside->id)))
            ->whereIn('status', ['merged', 'done'])
            ->where('tasks_total', '>', 0)
            ->orderByDesc('spec_updated_at')
            ->limit(max(1, (int) config('ring-master.improve.replay_specs', 3)))
            ->get()->all());
    }

    /**
     * How the job went the first time: review rounds, cost, CI fixes, whether it escalated.
     *
     * @return array{rounds: int, cost: float, ci_fixes: int, escalated: bool}
     */
    public function baseline(Spec $spec): array
    {
        $tasks = Task::query()->where('spec_id', $spec->id)->pluck('id');

        return [
            'rounds' => count($spec->rounds),
            'cost' => round((float) Run::query()->whereIn('task_id', $tasks)->sum('cost_usd'), 2),
            'ci_fixes' => $spec->ci_fixes,
            'escalated' => $spec->attentionItems()->where('kind', AttentionKind::Escalation)->where('source', 'build')->exists(),
        ];
    }

    public static function dir(Run $run): string
    {
        return storage_path("app/private/replays/{$run->id}");
    }

    /**
     * Builds the throwaway checkout for a replay run; returns the folder to run the factory in.
     */
    public function prepare(Run $run, Improvement $improvement, Spec $spec): string
    {
        $ringside = Improver::ringside($improvement->workspace) ?? throw new RuntimeException('No Ringside checkout in this workspace to evaluate a branch of.');
        $root = self::dir($run);
        File::ensureDirectoryExists($root);
        $package = $spec->project;

        // Ringside at the improvement's branch.
        $ref = $this->git($ringside->path, ['rev-parse', '--verify', '--quiet', (string) $improvement->branch], false) !== null
            ? (string) $improvement->branch
            : $this->fetched($ringside, (string) $improvement->branch);
        $this->git($ringside->path, ['worktree', 'add', '--detach', $root.'/Ringside', $ref]);

        // The package as it was when the job started: the parent of the commit that added its spec.
        $added = trim((string) $this->git($package->path, ['log', '--diff-filter=A', '--format=%H', '-1', '--', $spec->dir.'/spec.md']));

        if ($added === '') {
            throw new RuntimeException("Cannot find the commit that started {$spec->key} in {$package->folder}.");
        }

        $checkout = $root.'/'.$package->folder;
        $this->git($package->path, ['worktree', 'add', '--detach', $checkout, $added.'^']);
        $this->copySpec($package, $spec, $checkout);
        $this->install($package, $checkout, $root.'/Ringside');

        return $checkout;
    }

    /**
     * Records a finished replay against the job's first run, removes its
     * checkout, and marks the improvement evaluated once none is left.
     */
    public function finish(Run $run): void
    {
        $improvement = $run->improvement_id === null ? null : Improvement::query()->find($run->improvement_id);
        $spec = $run->replay_of === null ? null : Spec::query()->where('project_id', $run->project_id)->where('key', $run->replay_of)->first();

        if ($improvement !== null && $spec !== null) {
            // The factory writes it in the spec folder of the checkout it ran in, or of its replay worktree.
            $file = glob(self::dir($run).'/*/'.$spec->dir.'/replay.json')[0] ?? null;
            $replay = is_string($file) ? json_decode((string) file_get_contents($file), true) : null;
            $results = $improvement->results ?? [];
            $results['specs'][$spec->key] = [
                'project' => $spec->project->folder,
                'title' => $spec->title,
                'baseline' => $this->baseline($spec),
                'replay' => is_array($replay) ? [...$replay, 'cost' => (float) $run->cost_usd, 'run' => $run->id] : ['status' => 'no result', 'reasons' => [$run->summary ?? 'the replay wrote no replay.json'], 'cost' => (float) $run->cost_usd, 'run' => $run->id],
            ];
            $improvement->update(['results' => $results]);
        }

        $this->teardown($run);

        if ($improvement !== null && $improvement->status === ImprovementStatus::Evaluating && ($improvement->results['pending'] ?? []) === []
            && ! Run::query()->where('improvement_id', $improvement->id)->where('kind', RunKind::Replay)->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->exists()) {
            $improvement->update(['status' => ImprovementStatus::Evaluated]);
            Activity::record('improvement.evaluated', "Evaluated: {$improvement->title}");
            $this->notifier->send('A factory improvement was evaluated', [$improvement->title], '/factory');
        }
    }

    public function teardown(Run $run): void
    {
        $root = self::dir($run);

        if (is_dir($root)) {
            File::deleteDirectory($root);
        }

        foreach (Project::query()->whereKey(array_filter([$run->project_id, $run->improvement_id === null ? null : Improver::ringside(Improvement::query()->findOrFail($run->improvement_id)->workspace)?->id]))->get() as $project) {
            $this->git($project->path, ['worktree', 'prune'], false);

            // Branches the replay's factory made: never the job's real ones.
            foreach (array_filter(explode("\n", trim((string) $this->git($project->path, ['for-each-ref', '--format=%(refname:short)', 'refs/heads/ring-master/'], false)))) as $branch) {
                if ($run->replay_of !== null && str_starts_with($branch, 'ring-master/'.$run->replay_of) && str_contains($branch, 'replay')) {
                    $this->git($project->path, ['branch', '-D', $branch], false);
                }
            }
        }
    }

    private function fetched(Project $ringside, string $branch): string
    {
        $this->git($ringside->path, ['fetch', '--quiet', 'origin', $branch]);

        return 'origin/'.$branch;
    }

    /**
     * Copies the job's spec in as approved and unbuilt, without what the first run produced.
     */
    private function copySpec(Project $package, Spec $spec, string $checkout): void
    {
        $from = $package->path.'/'.$spec->dir;
        $to = $checkout.'/'.$spec->dir;
        File::ensureDirectoryExists($to);

        foreach (['spec.md', 'shape.md', 'references.md', 'standards.md', 'tasks.md', 'meta.yml'] as $file) {
            if (is_file($from.'/'.$file)) {
                File::copy($from.'/'.$file, $to.'/'.$file);
            }
        }

        if (is_file($to.'/tasks.md')) {
            file_put_contents($to.'/tasks.md', (string) preg_replace('/^(\s*[-*]\s*)\[[xX]\]/m', '$1[ ]', (string) file_get_contents($to.'/tasks.md')));
        }

        $meta = is_file($to.'/meta.yml') ? Yaml::parseFile($to.'/meta.yml') : [];
        $meta = is_array($meta) ? $meta : [];
        unset($meta['pr'], $meta['branch'], $meta['review_passes']);
        file_put_contents($to.'/meta.yml', Yaml::dump([...$meta, 'status' => 'approved'], 4, 2));
    }

    /**
     * Installs the checkout with Ringside from the improvement's worktree and
     * every other path repository pointing at the real sibling checkouts.
     */
    private function install(Project $package, string $checkout, string $ringside): void
    {
        $local = is_file($package->path.'/composer.local.json');
        $manifest = json_decode((string) file_get_contents($package->path.'/'.($local ? 'composer.local.json' : 'composer.json')), true);

        if (! is_array($manifest)) {
            throw new RuntimeException("{$package->folder}'s composer manifest does not parse.");
        }

        $repositories = [['type' => 'path', 'url' => $ringside, 'options' => ['symlink' => true]]];

        foreach (is_array($manifest['repositories'] ?? null) ? $manifest['repositories'] : [] as $repository) {
            if (is_array($repository) && ($repository['type'] ?? null) === 'path' && is_string($repository['url'] ?? null) && ! str_starts_with($repository['url'], '/')) {
                $repository['url'] = $package->path.'/'.$repository['url'];
            }

            $repositories[] = $repository;
        }

        $manifest['repositories'] = $repositories;
        file_put_contents($checkout.'/composer.replay.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $lock = $package->path.'/'.($local ? 'composer.local.lock' : 'composer.lock');

        if (is_file($lock)) {
            File::copy($lock, $checkout.'/composer.replay.lock');
        }

        // false unsets: RingMaster's own .env must not reach the package's tools.
        $env = [...array_fill_keys(Runner::ownEnv(), false), 'COMPOSER' => 'composer.replay.json'];
        $this->run($checkout, ['composer', 'update', 'refactor-circus/ringside', '--with-dependencies', '--no-interaction', '--no-scripts'], $env, 900);
        $this->run($checkout, ['vendor/bin/ringside', 'sync', '--force'], $env, 120);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $path, array $args, bool $throw = true): ?string
    {
        $result = Process::path($path)->timeout(120)->run(['git', ...$args]);

        if (! $result->successful()) {
            if ($throw) {
                throw new RuntimeException('git '.implode(' ', $args).': '.trim($result->errorOutput()));
            }

            return null;
        }

        return $result->output();
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     */
    private function run(string $path, array $command, array $env, int $timeout): void
    {
        $result = Process::path($path)->env($env)->timeout($timeout)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(implode(' ', $command).': '.mb_substr(trim($result->errorOutput() ?: $result->output()), -1500));
        }
    }
}
