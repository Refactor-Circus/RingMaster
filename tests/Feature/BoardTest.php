<?php

use App\Actions\ScanWorkspaces;
use App\Livewire\Board;
use App\Models\Project;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
});

it('shows every column and puts each spec in its own', function () {
    $this->get(route('board'))
        ->assertOk()
        ->assertSeeInOrder(['Ticket', 'Spec', 'Building', 'Needs you', 'In review', 'Done'])
        ->assertSee('CSV import')
        ->assertSee('#42')
        ->assertSee('2 questions')
        ->assertSee('Tasks 1/2')
        ->assertSee('Older');
});

it('filters by project and by title', function () {
    $alpha = Project::query()->where('folder', 'Alpha')->sole();

    Livewire::test(Board::class)
        ->set('search', 'CSV')
        ->assertSee('CSV import')
        ->assertDontSee('Older')
        ->set('search', '')
        ->set('project', (string) Project::query()->where('folder', 'Beta')->sole()->id)
        ->assertDontSee('CSV import')
        ->set('project', (string) $alpha->id)
        ->assertSee('CSV import');
});

it('hides the specs of disabled workspaces', function () {
    Workspace::query()->update(['enabled' => false]);

    $this->get(route('board'))->assertOk()->assertDontSee('CSV import');
});

it('tells you how to add a workspace when there are none', function () {
    config(['ring-master.workspaces' => []]);
    app(ScanWorkspaces::class)->handle();

    $this->get(route('board'))->assertOk()->assertSee('No projects yet');
});
