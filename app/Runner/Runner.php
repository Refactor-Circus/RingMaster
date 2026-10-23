<?php

namespace App\Runner;

use App\Actions\ClaimTasks;
use App\Actions\ScanWorkspaces;
use App\Enums\AttentionStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

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
    ) {}

    public static function paused(): bool
    {
        return (bool) Cache::get(self::PAUSED, false);
    }

    public static function pause(bool $paused): void
    {
        Cache::forever(self::PAUSED, $paused);
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
        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    public function tick(): void
    {
        Cache::put(self::HEARTBEAT, now()->getTimestamp(), 600);

        $this->scan->ifStale();
        $this->claims->expire();
        $this->reap();
        $this->stopRequested();

        if (self::paused() || $this->stopping) {
            return;
        }

        $limit = max(1, (int) config('ring-master.runner.concurrency', 1));

        while (count($this->processes) + count($this->adopted) < $limit && $this->startNext()) {
            // keep starting until the limit is reached or nothing is left
        }
    }

    private bool $stopping = false;

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

    private function start(Project $project, RunKind $kind, ?Task $task = null, ?string $session = null): void
    {
        $run = Run::query()->create([
            'project_id' => $project->id,
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

        $process = proc_open(
            self::detached($this->command->for($run)),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log.'.err', 'w']],
            $pipes,
            $project->path,
        );

        if (! is_resource($process)) {
            $this->finish($run, null, 'Could not start `'.config('ring-master.runner.claude_bin').'`.');

            return;
        }

        $this->processes[$run->id] = $process;
        $run->update(['pid' => proc_get_status($process)['pid']]);

        $label = $kind === RunKind::Task ? "Run #{$run->id} started" : "Run #{$run->id} started to apply answers in {$project->folder}";
        Activity::record('run.started', $label, $task);
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

        $run->update([
            'status' => $stopped ? RunStatus::Stopped : ($ok ? RunStatus::Succeeded : RunStatus::Failed),
            'exit_code' => $exitCode,
            'cost_usd' => $result['cost_usd'] ?? null,
            'summary' => $reason ?? ($result === null ? null : Str::limit($result['text'], 2000)),
            'finished_at' => now(),
        ]);

        $task = $run->task_id === null ? null : Task::query()->find($run->task_id);
        Activity::record('run.finished', "Run #{$run->id} {$run->status->value}".($run->cost_usd !== null ? " (\${$run->cost_usd})" : ''), $task);

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
     * A task the run still holds without a spec goes back to the queue, or
     * fails once it has used its attempts. One with a spec follows the spec.
     */
    private function settle(Task $task, Run $run): void
    {
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
    private static function overlaps(string $path, array $busy): bool
    {
        foreach ($busy as $other) {
            if ($path === $other || str_starts_with($path, $other.'/') || str_starts_with($other, $path.'/')) {
                return true;
            }
        }

        return false;
    }
}
