<?php

use App\Actions\ScanWorkspaces;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Spec;
use App\Models\Task;
use App\Models\Workspace;
use App\Runner\Runner;
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

    $queued = Task::query()->where('status', TaskStatus::Queued)->whereHas('project', fn (Builder $query) => $query->within($path))->count();
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
        $runner->tick();
        sleep(max(1, (int) config('ring-master.runner.tick_seconds', 5)));
    } while (! $runner->stopping());

    $runner->drain();
})->purpose('Run headless Claude sessions for queued tasks and answers waiting to be applied');

Schedule::command('ring-master:scan')->everyMinute()->withoutOverlapping();
