<?php

use App\Livewire\Workspaces;
use App\Models\Project;
use App\Models\Workspace;
use Livewire\Livewire;

it('adds a folder and scans it straight away', function () {
    $root = fixtureWorkspace();

    Livewire::test(Workspaces::class)
        ->set('path', $root)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('path', '')
        ->assertSee('acme/alpha');

    expect(Workspace::query()->sole())->path->toBe($root)->from_config->toBeFalse()
        ->and(Project::query()->count())->toBe(3);
});

it('rejects a folder that does not exist', function () {
    Livewire::test(Workspaces::class)
        ->set('path', '/no/such/folder')
        ->call('add')
        ->assertHasErrors(['path']);
});

it('disables and removes added folders, but only disables configured ones', function () {
    $added = Workspace::query()->create(['path' => fixtureWorkspace(), 'name' => 'added']);
    $configured = Workspace::query()->create(['path' => fixtureWorkspace(), 'name' => 'configured', 'from_config' => true]);

    Livewire::test(Workspaces::class)
        ->call('toggle', $configured->id)
        ->call('remove', $added->id);

    expect($configured->fresh()->enabled)->toBeFalse()
        ->and($added->fresh())->toBeNull();

    Livewire::test(Workspaces::class)->call('remove', $configured->id)->assertNotFound();

    expect($configured->fresh())->not->toBeNull();
});
