<?php

use App\Actions\ScanWorkspaces;
use App\Models\Spec;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ring-master:scan', function (ScanWorkspaces $scan) {
    $scan->handle();

    $this->info(sprintf('Scanned %d workspace(s): %d spec(s).', Workspace::query()->where('enabled', true)->count(), Spec::query()->count()));
})->purpose('Mirror every enabled workspace\'s projects and spec folders into the database');

Schedule::command('ring-master:scan')->everyMinute()->withoutOverlapping();
