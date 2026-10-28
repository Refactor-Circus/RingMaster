<?php

use App\Http\Controllers\ActionController;
use App\Livewire\Board;
use App\Livewire\Campaigns;
use App\Livewire\Factory;
use App\Livewire\Inbox;
use App\Livewire\Runs;
use App\Livewire\Settings;
use App\Livewire\ShowBriefing;
use App\Livewire\ShowCampaign;
use App\Livewire\ShowRun;
use App\Livewire\ShowSpec;
use App\Livewire\ShowTask;
use App\Livewire\Tasks;
use App\Livewire\Workflow;
use App\Livewire\Workspaces;
use Illuminate\Support\Facades\Route;

Route::get('/', Board::class)->name('board');
Route::get('/inbox', Inbox::class)->name('inbox');
Route::get('/tasks', Tasks::class)->name('tasks');
Route::get('/tasks/{task}', ShowTask::class)->name('tasks.show');
Route::get('/specs/{spec}', ShowSpec::class)->name('specs.show');
Route::get('/runs', Runs::class)->name('runs');
Route::get('/runs/{run}', ShowRun::class)->name('runs.show');
Route::get('/workflow', Workflow::class)->name('workflow');
Route::get('/workspaces', Workspaces::class)->name('workspaces');
Route::get('/settings', Settings::class)->name('settings');
Route::get('/briefing', ShowBriefing::class)->name('briefing');
Route::get('/briefing/{briefing}', ShowBriefing::class)->name('briefing.show');
Route::get('/campaigns', Campaigns::class)->name('campaigns');
Route::get('/campaigns/{campaign}', ShowCampaign::class)->name('campaigns.show');
Route::get('/factory', Factory::class)->name('factory');

// Notification buttons: signed, single use; GET confirms, POST acts.
Route::get('/act/{token}', [ActionController::class, 'show'])->name('act');
Route::post('/act/{token}', [ActionController::class, 'perform'])->name('act.perform');
