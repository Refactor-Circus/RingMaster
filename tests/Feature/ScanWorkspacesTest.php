<?php

use App\Actions\ScanWorkspaces;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Workspace;
use App\Support\ProjectDiscovery;
use Illuminate\Support\Facades\File;

it('finds a workspace root, its listed packages and nothing else', function () {
    $root = fixtureWorkspace();

    expect((new ProjectDiscovery)->discover($root))->toBe([
        ['path' => $root, 'folder' => basename($root), 'is_workspace' => true],
        ['path' => $root.'/Alpha', 'folder' => 'Alpha', 'is_workspace' => false],
        ['path' => $root.'/Beta', 'folder' => 'Beta', 'is_workspace' => false],
    ]);
});

it('treats a folder with a composer.json as one project, and any other folder as a parent of projects', function () {
    $single = fixtureFolder(['composer.json' => '{"name": "acme/solo"}', 'Sub/composer.json' => '{}']);
    $parent = fixtureFolder(['One/composer.json' => '{}', 'Two/composer.json' => '{}', 'notes/readme.md' => 'x']);

    expect(array_column((new ProjectDiscovery)->discover($single), 'folder'))->toBe([basename($single)])
        ->and(array_column((new ProjectDiscovery)->discover($parent), 'folder'))->toBe(['One', 'Two']);
});

it('expands a leading tilde to the home folder', function () {
    expect(ProjectDiscovery::expand('~/Projects'))->toEndWith('/Projects')->not->toStartWith('~')
        ->and(ProjectDiscovery::expand('/srv/code'))->toBe('/srv/code');
});

it('mirrors projects and specs from the configured workspaces', function () {
    $root = fixtureWorkspace();
    config(['ring-master.workspaces' => [$root]]);

    app(ScanWorkspaces::class)->handle();

    $import = Spec::query()->where('key', '2026-10-23-1430-import')->sole();

    expect(Workspace::query()->sole())->from_config->toBeTrue()->error->toBeNull()
        ->and(Project::query()->orderBy('folder')->pluck('name')->all())->toBe(['acme/alpha', 'acme/beta', 'acme/workspace'])
        ->and($import)
        ->title->toBe('CSV import')
        ->column->toBe('Needs you')
        ->questions->toBe(2)
        ->tasks_done->toBe(1)
        ->tasks_total->toBe(2)
        ->and($import->ticketKey())->toBe('#42')
        ->and(array_column($import->latestReports(), 'verdict', 'name'))->toBe(['gate' => 'pass', 'code' => 'changes'])
        ->and(Spec::query()->where('key', '2026-10-20-0900-older')->sole()->column)->toBe('Done');
});

it('drops specs and projects that are gone from disk, and configured workspaces that are gone from config', function () {
    $root = fixtureWorkspace();
    config(['ring-master.workspaces' => [$root]]);
    app(ScanWorkspaces::class)->handle();

    File::deleteDirectory($root.'/Alpha/docs/agent/specs/2026-10-20-0900-older');
    File::put($root.'/ring-master.yml', "workspace:\n  packages: [Alpha]\n");
    app(ScanWorkspaces::class)->handle();

    expect(Spec::query()->pluck('key')->all())->toBe(['2026-10-23-1430-import'])
        ->and(Project::query()->pluck('folder')->sort()->values()->all())->toBe(['Alpha', basename($root)]);

    config(['ring-master.workspaces' => []]);
    app(ScanWorkspaces::class)->handle();

    expect(Workspace::query()->count())->toBe(0)->and(Spec::query()->count())->toBe(0);
});

it('records a missing folder instead of failing the scan', function () {
    $workspace = Workspace::query()->create(['path' => '/no/such/folder', 'name' => 'gone']);

    app(ScanWorkspaces::class)->handle();

    expect($workspace->fresh()->error)->toBe('Folder not found.');
});

it('scans only when the last scan is stale', function () {
    $root = fixtureWorkspace();
    config(['ring-master.workspaces' => [$root], 'ring-master.scan.stale_after' => 60]);
    app(ScanWorkspaces::class)->handle();

    File::deleteDirectory($root.'/Alpha/docs/agent/specs/2026-10-20-0900-older');
    app(ScanWorkspaces::class)->ifStale();
    expect(Spec::query()->count())->toBe(2);

    $this->travel(61)->seconds();
    app(ScanWorkspaces::class)->ifStale();
    expect(Spec::query()->count())->toBe(1);
});

it('scans from the command line', function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()]]);

    $this->artisan('ring-master:scan')->expectsOutputToContain('Scanned 1 workspace(s): 2 spec(s).')->assertSuccessful();
});
