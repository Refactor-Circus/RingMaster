<?php

namespace App\Runner;

use App\Actions\Campaigns;
use App\Actions\ClaimTasks;
use App\Actions\Improvements;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskDependencies;
use App\Enums\AttentionStatus;
use App\Enums\ImprovementStatus;
use App\Enums\RunFailure;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Support\Briefings;
use App\Support\Dependabot;
use App\Support\Improver;
use App\Support\Janitor;
use App\Support\NightShift;
use App\Support\Notifier;
use App\Support\Planner;
use App\Support\PullRequests;
use App\Support\Replays;
use App\Support\Signals;
use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts and watches headless Claude sessions. Answers waiting to be applied
 * go first (they unblock stopped jobs), then queued tasks in pickup order.
 * Only one session works in a folder tree at a time, so a workspace root
 * and its packages never run at once.
 */
final class Runner
{
    public const string PAUSED = 'ring-master.runner.paused';

    public const string HEARTBEAT = 'ring-master.runner.heartbeat';

    /** Set for the tick in which a runner sees new code, just before it restarts into it. */
    public const string OUTDATED = 'ring-master.runner.outdated';

    /** Set while the last gh check of PRs in review is recent. */
    public const string PR_CHECK = 'ring-master.runner.pr-check';

    /** Set while the last janitor pass is recent. */
    public const string JANITOR = 'ring-master.runner.janitor';

    /** Concurrency set on the Runs page; it wins over the config without a restart. */
    public const string CONCURRENCY = 'ring-master.runner.concurrency';

    /** Until when nothing new starts: a run hit a usage or rate limit. */
    public const string BACKOFF = 'ring-master.runner.backoff';

    /** Folders whose changes a running runner must load: code, config and installed packages. */
    private const array CODE = ['app', 'config', 'routes', 'resources/runner', 'composer.lock', 'vendor/composer/installed.json', 'vendor/refactor-circus/ringside/src'];

    private readonly string $booted;

    /** @var array<int, resource> run id => process */
    private array $processes = [];

    /** @var array<int, int> run id => pid: live sessions a previous runner started */
    private array $adopted = [];

    public function __construct(
        private readonly ClaimTasks $claims,
        private readonly ScanWorkspaces $scan,
        private readonly ClaudeCommand $command,
        private readonly RunLog $log,
        private readonly Notifier $notifier,
        private readonly TaskDependencies $dependencies,
        private readonly PullRequests $pullRequests,
        private readonly Janitor $janitor,
        private readonly Dependabot $dependabot,
        private readonly Planner $planner,
        private readonly Improver $improver,
        private readonly Replays $replays,
        private readonly Improvements $improvements,
        private readonly Signals $signals,
        private readonly Briefings $briefings,
        private readonly Campaigns $campaigns,
    ) {
        $this->booted = self::codeVersion();
    }

    /**
     * The newest modification time across the app's code, so a long-running
     * runner can tell that what it loaded is out of date.
     */
    public static function codeVersion(): string
    {
        $newest = 0;
        clearstatcache();

        foreach (self::CODE as $path) {
            $path = base_path($path);

            if (is_file($path)) {
                $newest = max($newest, (int) filemtime($path));
            } elseif (is_dir($path)) {
                foreach (File::allFiles($path) as $file) {
                    $newest = max($newest, $file->getMTime());
                }
            }
        }

        return (string) $newest;
    }

    /**
     * Whether the code changed since this runner started, and the change
     * loads: then it restarts into it. A PHP file that does not compile (an
     * edit caught halfway) is reported once and the runner carries on with
     * the code it has, instead of re-executing into a fatal error.
     */
    public function outdated(): bool
    {
        $version = self::codeVersion();

        if ($version === $this->booted) {
            return false;
        }

        $broken = self::broken((int) $this->booted);

        if ($broken !== []) {
            if (Cache::add('ring-master.runner.broken.'.$version, true, now()->addDay())) {
                Log::error('RingMaster runner: not restarting into code that does not compile', $broken);
                $this->notifier->send('The runner is not restarting', ['New code does not compile: '.implode('; ', array_keys($broken))], '/runs');
            }

            return false;
        }

        return true;
    }

    /**
     * PHP files changed since $since that fail `php -l`, with the error.
     *
     * @return array<string, string>
     */
    public static function broken(int $since): array
    {
        $broken = [];
        clearstatcache();

        foreach (self::CODE as $path) {
            $path = base_path($path);

            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php' || $file->getMTime() <= $since) {
                    continue;
                }

                $lint = Process::run([PHP_BINARY, '-l', $file->getPathname()]);

                if (! $lint->successful()) {
                    $broken[str_replace(base_path().'/', '', $file->getPathname())] = trim($lint->output().' '.$lint->errorOutput());
                }
            }
        }

        return $broken;
    }

    public static function waitingToRestart(): bool
    {
        return (bool) Cache::get(self::OUTDATED, false);
    }

    public static function paused(): bool
    {
        return (bool) Cache::get(self::PAUSED, false);
    }

    public static function pause(bool $paused): void
    {
        Cache::forever(self::PAUSED, $paused);
    }

    /**
     * How many sessions may run at once: the Runs page's setting, else the
     * night shift's while it runs, else the config.
     */
    public static function concurrency(): int
    {
        $set = Cache::get(self::CONCURRENCY);

        return max(1, is_int($set) ? $set : (NightShift::concurrency() ?? (int) config('ring-master.runner.concurrency', 1)));
    }

    public static function setConcurrency(?int $concurrency): void
    {
        $concurrency === null ? Cache::forget(self::CONCURRENCY) : Cache::forever(self::CONCURRENCY, max(1, min(20, $concurrency)));
    }

    /**
     * When the runner may start sessions again after a usage limit, or null.
     */
    public static function backoffUntil(): ?int
    {
        $until = Cache::get(self::BACKOFF);

        return is_int($until) && $until > now()->getTimestamp() ? $until : null;
    }

    /**
     * Whether this machine is too loaded to start another session: the
     * one-minute load average per CPU is above runner.max_load (null: no cap).
     */
    public static function overloaded(): bool
    {
        $max = config('ring-master.runner.max_load');

        if (! is_numeric($max) || (float) $max <= 0 || ! function_exists('sys_getloadavg')) {
            return false;
        }

        $load = sys_getloadavg();

        return is_array($load) && (float) $max < $load[0] / self::cpus();
    }

    public static function cpus(): int
    {
        return (int) Cache::rememberForever('ring-master.runner.cpus', function (): int {
            foreach ([['sysctl', '-n', 'hw.ncpu'], ['nproc']] as $command) {
                $result = Process::run($command);

                if ($result->successful() && (int) trim($result->output()) > 0) {
                    return (int) trim($result->output());
                }
            }

            return 4;
        });
    }

    /**
     * Why the runner starts nothing new right now, or null when it would start
     * the next session within a tick. A job's own wait, such as another run in
     * its folder tree, is the caller's to add.
     */
    public static function holdReason(): ?string
    {
        $active = Run::query()->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->count();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return match (true) {
            ! self::alive() => 'No runner is running. Start one with `php artisan ring-master:work`.',
            self::paused() => 'The runner is paused. Resume it on the Runs page.',
            self::waitingToRestart() => 'The runner is restarting to load new code; it starts again within a few seconds.',
            $active >= self::concurrency() => 'Waiting for a free slot: the runner is at its concurrency limit.',
            self::backoffUntil() !== null => 'The runner is backing off after a usage limit; it starts again by itself.',
            NightShift::exhausted() => 'Tonight\'s night shift budget is spent: it starts once the window ends.',
            self::overloaded() => 'The machine is busy'.(is_array($load) ? sprintf(' (load %.1f per CPU, cap %s)', $load[0] / self::cpus(), config('ring-master.runner.max_load')) : '').': the runner waits for the load to drop before starting more.',
            default => null,
        };
    }

    /**
     * Whether a runner process has checked in recently.
     */
    public static function alive(): bool
    {
        $last = Cache::get(self::HEARTBEAT);

        return is_int($last) && now()->getTimestamp() - $last <= max(15, 3 * (int) config('ring-master.runner.tick_seconds', 5));
    }

    /**
     * Runs left "running" by a runner that is gone. A session that is still
     * alive (sessions run in their own process session, so they outlive the
     * runner) is adopted and watched until it ends; the rest are marked
     * failed and their tasks given back.
     */
    public function recoverOrphans(): void
    {
        foreach (Run::query()->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get() as $run) {
            if (isset($this->processes[$run->id]) || isset($this->adopted[$run->id])) {
                continue;
            }

            if ($run->pid !== null && self::pidAlive($run->pid)) {
                $this->adopted[$run->id] = $run->pid;
            } else {
                $this->finish($run, null, 'The runner stopped while this run was going.');
            }
        }
    }

    /**
     * Stops every session this runner watches (a second Ctrl+C).
     */
    public function terminateAll(): void
    {
        Run::query()->whereKey([...array_keys($this->processes), ...array_keys($this->adopted)])->where('status', RunStatus::Running)->update(['status' => RunStatus::Stopping]);
        $this->stopRequested();
    }

    private static function pidAlive(int $pid): bool
    {
        // A session this process started before it re-executed itself is still
        // its child: once it exits it stays a zombie, which kill(0) counts as
        // alive, until it is waited for.
        if (function_exists('pcntl_waitpid') && pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
            return false;
        }

        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    public function tick(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), 600);

        $this->scan->ifStale();
        $this->claims->expire();
        // PR state comes from gh, so it is checked every few minutes, not every tick.
        if (Cache::add(self::PR_CHECK, true, 300)) {
            $this->pullRequests->refresh();
        }

        $this->dependencies->unblockFinished();
        $this->campaigns->settle();
        $this->improvements->track();

        if (config('ring-master.janitor.enabled') && Cache::add(self::JANITOR, true, max(1, (int) config('ring-master.janitor.every_minutes', 60)) * 60)) {
            $this->janitor->run();

            if (config('ring-master.janitor.dependabot')) {
                $this->dependabot->triage();
            }

            if (config('ring-master.signals.enabled')) {
                $this->signals->collect();
            }
        }

        // One try per day: a briefing that fails is not retried every tick.
        if (Briefings::due() && Cache::add('ring-master.briefing.'.now()->toDateString(), true, now()->addDay())) {
            $this->briefings->generate();
        }
        $this->reap();
        $this->stopRequested();

        $outdated = $this->outdated();
        Cache::put(self::OUTDATED, $outdated, 600);

        if (self::paused() || $this->stopping || $outdated || self::backoffUntil() !== null || $this->nightBudgetSpent()) {
            return;
        }

        $limit = self::concurrency();

        while (count($this->processes) + count($this->adopted) < $limit && ! self::overloaded() && $this->startNext()) {
            // keep starting until the limit is reached or nothing is left
        }
    }

    private bool $stopping = false;

    /**
     * Whether tonight's budget is used up; says so once a night.
     */
    private function nightBudgetSpent(): bool
    {
        if (! NightShift::exhausted()) {
            return false;
        }

        if (Cache::add('ring-master.night-shift.spent.'.NightShift::startedAt()?->getTimestamp(), true, now()->addDay())) {
            $spent = number_format(NightShift::spent(), 2);
            Activity::record('runner.night-budget', "Night shift budget spent (\${$spent}): nothing new starts until morning");
            $this->notifier->send('Night shift budget spent', ["\${$spent} of \$".number_format((float) NightShift::budget(), 2).'; the rest waits for the morning'], '/runs');
        }

        return true;
    }

    /**
     * Stop starting new runs (Ctrl+C or SIGTERM); runs already going finish.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    public function busy(): bool
    {
        return $this->processes !== [] || $this->adopted !== [];
    }

    /**
     * Waits for every run this runner started to finish.
     */
    public function drain(): void
    {
        while ($this->busy()) {
            usleep(100_000);
            $this->reap();
            $this->stopRequested();
        }
    }

    private function startNext(): bool
    {
        $busy = $this->busyPaths();

        foreach ($this->projectsWithAnswers() as $project) {
            if (! self::overlaps($project->path, $busy)) {
                $this->start($project, RunKind::Answers);

                return true;
            }
        }

        foreach ($this->planner->due() as $root) {
            if (! self::overlaps($root->path, $busy)) {
                $this->planner->started($root);
                $this->start($root, RunKind::Plan);

                return true;
            }
        }

        foreach ($this->improver->due() as $root) {
            if (! self::overlaps($root->path, $busy)) {
                $this->improver->started($root);
                $this->start($root, RunKind::Improve);

                return true;
            }
        }

        if ($this->startReplay($busy)) {
            return true;
        }

        foreach ($this->claims->candidates(25) as $task) {
            if (! self::overlaps($task->project->path, $busy)) {
                $session = 'runner-'.Str::lower(Str::random(6));
                $this->claims->claim($task->id, $session);
                $task->increment('attempts');
                $this->start($task->project, RunKind::Task, $task, $session);

                return true;
            }
        }

        return false;
    }

    /**
     * Starts the next replay an evaluation is waiting for, in a throwaway
     * checkout of its package. A checkout that cannot be built is recorded
     * as that replay's result.
     *
     * @param  list<string>  $busy
     */
    private function startReplay(array $busy): bool
    {
        foreach (Improvement::query()->where('status', ImprovementStatus::Evaluating)->get() as $improvement) {
            $results = $improvement->results ?? [];
            /** @var list<array{project_id: int, key: string}> $pending */
            $pending = $results['pending'] ?? [];

            foreach ($pending as $i => $next) {
                $project = Project::query()->find($next['project_id']);

                if ($project === null || self::overlaps($project->path, $busy)) {
                    continue;
                }

                array_splice($pending, $i, 1);
                $improvement->update(['results' => [...$results, 'pending' => $pending]]);
                $run = $this->start($project, RunKind::Replay, improvement: $improvement, replayOf: $next['key']);

                return $run !== null;
            }
        }

        return false;
    }

    private function start(Project $project, RunKind $kind, ?Task $task = null, ?string $session = null, ?Improvement $improvement = null, ?string $replayOf = null): ?Run
    {
        $run = Run::query()->create([
            'project_id' => $project->id,
            'improvement_id' => $improvement?->id,
            'replay_of' => $replayOf,
            'task_id' => $task?->id,
            'kind' => $kind,
            'session' => $session ?? 'runner-'.Str::lower(Str::random(6)),
            'status' => RunStatus::Running,
            'log_path' => '',
            'started_at' => now(),
        ]);

        $log = storage_path("app/private/runs/{$run->id}.jsonl");
        File::ensureDirectoryExists(dirname($log));
        $run->update(['log_path' => $log]);
        $cwd = $project->path;

        if ($kind === RunKind::Replay && $improvement !== null && $replayOf !== null) {
            try {
                $cwd = $this->replays->prepare($run, $improvement, $project->specs()->where('key', $replayOf)->firstOrFail());
            } catch (Throwable $e) {
                $this->finish($run, null, 'Could not set up the replay: '.$e->getMessage());

                return null;
            }
        }

        $process = proc_open(
            self::detached($this->command->for($run)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log.'.err', 'w']],
            $pipes,
            $cwd,
            self::sessionEnv(),
        );

        if (! is_resource($process)) {
            $this->finish($run, null, 'Could not start `'.config('ring-master.runner.claude_bin').'`.');

            return null;
        }

        $this->processes[$run->id] = $process;
        $run->update(['pid' => proc_get_status($process)['pid']]);

        $label = match ($kind) {
            RunKind::Task => "Run #{$run->id} started",
            RunKind::Answers => "Run #{$run->id} started to apply answers in {$project->folder}",
            RunKind::Plan => "Run #{$run->id} started planning {$project->folder}",
            RunKind::Improve => "Run #{$run->id} started analysing the factory for {$project->folder}",
            RunKind::Replay => "Run #{$run->id} started replaying {$replayOf} for improvement #{$improvement?->id}",
        };
        Activity::record('run.started', $label, $task);

        return $run;
    }

    /**
     * The runner's environment without what RingMaster's own .env put in it.
     * Laravel exports .env into the process, and a session's PHP tools
     * (Testbench, a package's tests) would read RingMaster's SESSION_DRIVER,
     * DB_CONNECTION or APP_ENV as their own.
     *
     * @return array<string, string>
     */
    public static function sessionEnv(): array
    {
        // claude -p stops background work 10 minutes after its last turn, which killed factory builds part-way.
        return [...array_diff_key(getenv(), array_flip(self::ownEnv())), 'CLAUDE_CODE_PRINT_BG_WAIT_CEILING_MS' => '0'];
    }

    /**
     * The variables RingMaster's own .env sets, to keep out of what it runs.
     *
     * @return list<string>
     */
    public static function ownEnv(): array
    {
        $file = app()->environmentFilePath();

        return is_file($file) ? array_map('strval', array_keys(Dotenv::parse((string) file_get_contents($file)))) : [];
    }

    /**
     * Wraps the command so it runs in its own process session, where the
     * runner's Ctrl+C can't reach it. Without posix and pcntl, as is.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private static function detached(array $command): array
    {
        return function_exists('posix_setsid') && function_exists('pcntl_exec')
            ? [PHP_BINARY, resource_path('runner/detach.php'), ...$command]
            : $command;
    }

    private function reap(): void
    {
        foreach ($this->adopted as $id => $pid) {
            if (! self::pidAlive($pid)) {
                unset($this->adopted[$id]);
                $run = Run::query()->find($id);

                if ($run !== null) {
                    $this->finish($run, null, adopted: true);
                }
            }
        }

        foreach ($this->processes as $id => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                continue;
            }

            proc_close($process);
            unset($this->processes[$id]);

            $run = Run::query()->find($id);

            if ($run !== null) {
                $this->finish($run, $status['exitcode']);
            }
        }
    }

    private function stopRequested(): void
    {
        foreach (Run::query()->whereKey([...array_keys($this->processes), ...array_keys($this->adopted)])->where('status', RunStatus::Stopping)->pluck('id') as $id) {
            if (isset($this->processes[$id])) {
                proc_terminate($this->processes[$id]);
            } elseif (function_exists('posix_kill')) {
                posix_kill($this->adopted[$id], 15);
            }
        }
    }

    /**
     * @param  bool  $adopted  a session a previous runner started: its exit code is unknown, so the log's result decides
     */
    private function finish(Run $run, ?int $exitCode, ?string $reason = null, bool $adopted = false): void
    {
        $result = $run->log_path === '' ? null : $this->log->result($run->log_path);
        $stopped = $run->status === RunStatus::Stopping;
        $ok = ! $stopped && $reason === null && ($exitCode === 0 || $adopted) && $result !== null && ! $result['is_error'];
        $failure = $ok || $stopped ? null : self::failure($reason, $result);

        $run->update([
            'status' => $stopped ? RunStatus::Stopped : ($ok ? RunStatus::Succeeded : RunStatus::Failed),
            'exit_code' => $exitCode,
            'cost_usd' => $result['cost_usd'] ?? null,
            'summary' => $reason ?? ($result === null ? null : Str::limit($result['text'], 2000)),
            'failure' => $failure,
            'refused' => ($result['refused'] ?? []) === [] ? null : $result['refused'],
            'finished_at' => now(),
        ]);

        $task = $run->task_id === null ? null : Task::query()->find($run->task_id);
        Activity::record('run.finished', "Run #{$run->id} {$run->status->value}".($failure !== null ? " ({$failure->label()})" : '').($run->cost_usd !== null ? " (\${$run->cost_usd})" : ''), $task);

        if ($failure === RunFailure::Limit) {
            $this->backOff($run);
        }

        // A limit or a broken start is not the task's fault: the attempt does not count.
        if ($task !== null && $failure?->free() && $task->attempts > 0) {
            $task->decrement('attempts');
        }

        if ($run->kind === RunKind::Plan) {
            $suggested = Task::query()->where('status', TaskStatus::Proposed)->where('created_at', '>=', $run->started_at)->where('proposal_reason', 'like', 'Planner:%')->count();
            $this->notifier->send('The planner is done', ["{$run->project->folder}: {$suggested} job(s) suggested"], '/inbox');
        }

        if ($run->kind === RunKind::Improve) {
            $proposed = Improvement::query()->where('workspace_id', $run->project->workspace_id)->where('created_at', '>=', $run->started_at)->count();
            $this->notifier->send('The factory analyst is done', ["{$run->project->folder}: {$proposed} improvement(s) proposed"], '/factory');
        }

        if ($run->kind === RunKind::Replay) {
            $this->replays->finish($run);
        }

        if ($task === null && $run->kind === RunKind::Answers && $run->status === RunStatus::Failed) {
            $this->notifier->send('Could not apply your answers', ["{$run->project->folder}: run #{$run->id} failed"], "/runs/{$run->id}");
        }

        if ($task !== null) {
            // Pick up a spec the session created, so the task follows it instead of going back to the queue.
            $this->scan->scan($run->project->workspace);
            $this->settle($task->refresh(), $run);
        }
    }

    /**
     * @param  array{text: string, is_error: bool, cost_usd: ?float, subtype: string, refused: list<string>}|null  $result
     */
    public static function failure(?string $reason, ?array $result): RunFailure
    {
        $text = $result === null ? '' : $result['text'];

        return match (true) {
            $reason !== null || $result === null => RunFailure::Environment,
            str_contains($result['subtype'], 'budget') || (bool) preg_match('/usage limit|rate limit|limit reached|overloaded|credit balance|quota/i', $text) => RunFailure::Limit,
            $result['refused'] !== [] => RunFailure::Permission,
            default => RunFailure::Work,
        };
    }

    /**
     * Starts nothing new for runner.limit_backoff_minutes, so every queued
     * task does not burn an attempt on the same limit.
     */
    private function backOff(Run $run): void
    {
        $minutes = max(1, (int) config('ring-master.runner.limit_backoff_minutes', 15));

        if (self::backoffUntil() === null) {
            $this->notifier->send('Runner backing off', ["Run #{$run->id} hit a usage limit; nothing new starts for {$minutes} minutes"], "/runs/{$run->id}");
        }

        Cache::put(self::BACKOFF, now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes));
        Activity::record('runner.backoff', "Usage limit: nothing new starts for {$minutes} minutes");
    }

    /**
     * A task the run still holds without a spec goes back to the queue, or
     * fails once it has used its attempts. One with a spec follows the spec.
     */
    private function settle(Task $task, Run $run): void
    {
        // The runner went away under a job mid-stage: give the claim up so it resumes now, not after the stall window.
        if ($task->claimed_by === $run->session && $task->spec_id !== null && $task->status->isOpen() && $run->failure === RunFailure::Environment) {
            $task->update(['claimed_by' => null, 'claimed_at' => null, 'lease_expires_at' => null]);
            Activity::record('task.status', "Run #{$run->id} was cut off; it resumes where its spec stands", $task);

            return;
        }

        if ($task->claimed_by !== $run->session || $task->spec_id !== null || ! $task->status->isOpen()) {
            return;
        }

        $failed = $task->attempts >= (int) config('ring-master.runner.max_attempts', 2);

        $task->update([
            'status' => $failed ? TaskStatus::Failed : TaskStatus::Queued,
            'claimed_by' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
        ]);

        Activity::record($failed ? 'task.failed' : 'task.status', $failed
            ? "Failed after {$task->attempts} attempt(s); see run #{$run->id}"
            : "Back in the queue: run #{$run->id} ended without a spec", $task);

        if ($failed) {
            $this->notifier->send('A task failed', ["{$task->project->folder}: {$task->title} (after {$task->attempts} attempts)"], "/tasks/{$task->id}");
        }
    }

    /**
     * Projects with answers newer than their last answers run, so a run that
     * could not apply them is not started again and again.
     *
     * @return list<Project>
     */
    private function projectsWithAnswers(): array
    {
        $projects = [];

        $items = AttentionItem::query()
            ->with('spec.project')
            ->where('status', AttentionStatus::Answered)
            ->whereHas('spec.project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->get()
            ->groupBy(fn (AttentionItem $item) => $item->spec->project_id);

        foreach ($items as $answers) {
            $project = $answers->first()->spec->project;
            $last = Run::query()->where('project_id', $project->id)->where('kind', RunKind::Answers)->latest('started_at')->value('started_at');

            if ($last === null || $answers->contains(fn (AttentionItem $item) => $item->answered_at !== null && $item->answered_at->greaterThan($last))) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    /**
     * @return list<string>
     */
    private function busyPaths(): array
    {
        return array_values(Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get()->map(fn (Run $run): string => $run->project->path)->all());
    }

    /**
     * @param  list<string>  $busy
     */
    public static function overlaps(string $path, array $busy): bool
    {
        foreach ($busy as $other) {
            if ($path === $other || str_starts_with($path, $other.'/') || str_starts_with($other, $path.'/')) {
                return true;
            }
        }

        return false;
    }
}
