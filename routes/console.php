<?php

use App\Actions\ScanWorkspaces;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Spec;
use App\Models\Task;
use App\Models\Workspace;
use App\Runner\Runner;
use App\Support\Briefings;
use App\Support\Dependabot;
use App\Support\Janitor;
use App\Support\Signals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ring-master:scan', function (ScanWorkspaces $scan) {
    $scan->handle();

    $this->info(sprintf('Scanned %d workspace(s): %d spec(s).', Workspace::query()->where('enabled', true)->count(), Spec::query()->count()));
})->purpose('Mirror every enabled workspace\'s projects and spec folders into the database');

Artisan::command('ring-master:summary {--project= : Only count work in this folder (a workspace root covers its packages)}', function (ScanWorkspaces $scan) {
    $scan->ifStale();

    $project = $this->option('project');
    $path = is_string($project) ? $project : null;

    $queued = Task::query()->where('status', TaskStatus::Queued)->whereNull('paused_at')->unblocked()->whereHas('project', fn (Builder $query) => $query->within($path))->count();
    $open = AttentionItem::query()->where('status', AttentionStatus::Open)->whereHas('spec.project', fn (Builder $query) => $query->within($path))->count();
    $answered = AttentionItem::query()->where('status', AttentionStatus::Answered)->whereHas('spec.project', fn (Builder $query) => $query->within($path))->count();

    if ($queued + $open + $answered === 0) {
        return;
    }

    $this->line(sprintf(
        'RingMaster: %d queued, %d need you, %d answer(s) to apply. Run /ring-master-inbox to work through them.',
        $queued, $open, $answered,
    ));
})->purpose('One line for a Claude session: queued tasks, questions that need you, answers to apply');

Artisan::command('ring-master:work {--once : Start what can start, wait for it to finish, then exit}', function (Runner $runner) {
    $runner->recoverOrphans();
    $this->info('RingMaster runner started'.(Runner::paused() ? ' (paused: resume it on the Runs page)' : '').'. Ctrl+C to stop.');

    if ($this->option('once')) {
        $runner->tick();
        $runner->drain();

        return;
    }

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        // First Ctrl+C: start nothing new and let running sessions finish. Second: stop them too.
        $interrupt = function () use ($runner): void {
            if ($runner->stopping()) {
                $this->warn('Stopping running sessions.');
                $runner->terminateAll();

                return;
            }

            $this->info('Finishing running sessions; press Ctrl+C again to stop them.');
            $runner->stop();
        };

        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $interrupt);
    }

    do {
        // One bad tick (a locked database, a scan error) must not take down the sessions this runner watches.
        try {
            $runner->tick();
        } catch (Throwable $e) {
            report($e);
            $this->error('Tick failed, retrying: '.$e->getMessage());
        }

        // New code (a deploy, an edit, a composer update): restart into it now. Sessions run detached
        // and log to files, so the new runner adopts the running ones and settles them when they end.
        if (! $runner->stopping() && $runner->outdated() && function_exists('pcntl_exec')) {
            $this->info('The code changed: restarting the runner to load it.');
            pcntl_exec(PHP_BINARY, [base_path('artisan'), 'ring-master:work']);
        }

        sleep(max(1, (int) config('ring-master.runner.tick_seconds', 5)));
    } while (! $runner->stopping());

    $runner->drain();
})->purpose('Run headless Claude sessions for queued tasks and answers waiting to be applied');

Artisan::command('ring-master:clean {--dry-run : Only list what would go} {--dependabot : Triage Dependabot PRs too}', function (Janitor $janitor, Dependabot $prs) {
    foreach ($janitor->run((bool) $this->option('dry-run')) as $folder => $report) {
        foreach ($report['updated'] as $change) {
            $this->line(($this->option('dry-run') ? 'would update' : 'updated')." {$folder}: {$change}");
        }

        foreach (['worktrees' => 'worktree', 'branches' => 'local branch'] as $part => $label) {
            foreach ($report[$part] as $item) {
                $this->line(($this->option('dry-run') ? 'would remove' : 'removed')." {$label} {$folder}: {$item}");
            }
        }

        foreach ($report['remote'] as $branch) {
            $this->line("merged on GitHub (approve in the Inbox to delete) {$folder}: {$branch}");
        }

        foreach ($report['skipped'] as $why) {
            $this->warn("kept {$folder}: {$why}");
        }
    }

    if ($this->option('dependabot') && ! $this->option('dry-run')) {
        $this->info($prs->triage().' Dependabot chore(s) raised.');
    }
})->purpose('Remove merged worktrees and local branches, and raise chores for merged branches on GitHub');

Artisan::command('ring-master:briefing {--quiet-notify : Make it without notifying}', function (Briefings $briefings) {
    $briefing = $briefings->generate(! $this->option('quiet-notify'));
    $data = $briefing->data;
    $this->info(sprintf('Briefing #%d: %d shipped, %d PR(s) to review, %d thing(s) need you, $%s spent.', $briefing->id, count($data['shipped']), count($data['review']), $data['needs']['count'], number_format((float) $data['runs']['cost'], 2)));
    $this->line(rtrim((string) config('ring-master.notifications.app_url'), '/')."/briefing/{$briefing->id}");
})->purpose('Make the morning briefing now');

Artisan::command('ring-master:signals', function (Signals $signals) {
    $this->info($signals->collect().' open signal(s).');
})->purpose('Read open issues, flaky checks and stale Dependabot PRs from GitHub for the planner');

Schedule::command('ring-master:scan')->everyMinute()->withoutOverlapping();
