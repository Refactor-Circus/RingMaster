<?php

use App\Livewire\Board;
use App\Livewire\Inbox;
use App\Livewire\Runs;
use App\Livewire\Settings;
use App\Livewire\ShowRun;
use App\Livewire\ShowSpec;
use App\Livewire\ShowTask;
use App\Livewire\Tasks;
use App\Livewire\Workspaces;
use Illuminate\Support\Facades\Route;

Route::get('/', Board::class)->name('board');
Route::get('/inbox', Inbox::class)->name('inbox');
Route::get('/tasks', Tasks::class)->name('tasks');
Route::get('/tasks/{task}', ShowTask::class)->name('tasks.show');
Route::get('/specs/{spec}', ShowSpec::class)->name('specs.show');
Route::get('/runs', Runs::class)->name('runs');
Route::get('/runs/{run}', ShowRun::class)->name('runs.show');
Route::get('/workspaces', Workspaces::class)->name('workspaces');
Route::get('/settings', Settings::class)->name('settings');
