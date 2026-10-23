<?php

use App\Livewire\Board;
use App\Livewire\ShowSpec;
use App\Livewire\Workspaces;
use Illuminate\Support\Facades\Route;

Route::get('/', Board::class)->name('board');
Route::get('/specs/{spec}', ShowSpec::class)->name('specs.show');
Route::get('/workspaces', Workspaces::class)->name('workspaces');
