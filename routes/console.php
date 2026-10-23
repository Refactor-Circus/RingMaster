<?php

use App\Actions\ScanWorkspaces;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Spec;
use App\Models\Task;
use App\Models\Workspace;
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

Schedule::command('ring-master:scan')->everyMinute()->withoutOverlapping();
