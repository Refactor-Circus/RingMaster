<?php

use App\Actions\Campaigns;
use App\Actions\ClaimTasks;
use App\Actions\ScanWorkspaces;
use App\Enums\CampaignStatus;
use App\Enums\TaskStatus;
use App\Livewire\Campaigns as CampaignsPage;
use App\Livewire\ShowCampaign;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\DependencyOrder;
use Livewire\Livewire;

beforeEach(function () {
    // Alpha requires Beta; Gamma (listed too) requires Alpha.
    $root = fixtureFolder([
        'ring-master.yml' => "workspace:\n  packages: [Alpha, Beta, Gamma]\n",
        'composer.json' => '{"name": "acme/workspace"}',
        'Alpha/composer.json' => '{"name": "acme/alpha", "require": {"acme/beta": "*"}}',
        'Beta/composer.json' => '{"name": "acme/beta", "require": {"php": "^8.5"}}',
        'Gamma/composer.json' => '{"name": "acme/gamma", "require-dev": {"acme/alpha": "*"}}',
    ]);
    config(['ring-master.workspaces' => [$root]]);
    app(ScanWorkspaces::class)->handle();
    $this->workspace = Workspace::query()->sole();
    $this->packages = Project::query()->where('is_workspace', false)->orderBy('folder')->get();
});

it('orders packages so each comes after the ones it requires', function () {
    $waves = app(DependencyOrder::class)->waves($this->packages)['waves'];

    expect(array_map(fn (array $wave) => array_map(fn (Project $p) => $p->folder, $wave), $waves))->toBe([['Beta'], ['Alpha'], ['Gamma']]);
});

it('launches a task per package that waits on the packages it requires', function () {
    $campaign = app(Campaigns::class)->launch($this->workspace, 'Pest 5', 'Move to Pest 5 and fix what breaks.', $this->packages->all());
    $task = fn (string $folder) => Task::query()->whereHas('project', fn ($q) => $q->where('folder', $folder))->sole();

    expect($campaign->tasks()->count())->toBe(3)
        ->and($task('Alpha')->dependencies->pluck('id')->all())->toBe([$task('Beta')->id])
        ->and($task('Gamma')->dependencies->pluck('id')->all())->toBe([$task('Alpha')->id])
        ->and($task('Alpha')->body)->toContain('Move to Pest 5')->toContain("Alpha's part of campaign #{$campaign->id}")->toContain('It requires Beta')
        ->and(app(ClaimTasks::class)->candidates(10)->pluck('id')->all())->toBe([$task('Beta')->id]);
});

it('previews the order and launches from the page, then shows the waves', function () {
    Livewire::test(CampaignsPage::class)
        ->assertSee('lands in this order')
        ->set('title', 'Pest 5')
        ->set('intent', 'Move to Pest 5.')
        ->call('launch')
        ->assertRedirect();

    Livewire::test(ShowCampaign::class, ['campaign' => Campaign::query()->sole()])
        ->assertSeeInOrder(['wave 1', 'Beta', 'wave 2', 'Alpha', 'wave 3', 'Gamma']);
});

it('is done when every task is, and cancelling cancels the open tasks', function () {
    $campaign = app(Campaigns::class)->launch($this->workspace, 'Pest 5', 'Move to Pest 5.', $this->packages->all());

    $campaign->tasks()->update(['status' => TaskStatus::Done]);
    app(Campaigns::class)->settle();
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Done);

    $other = app(Campaigns::class)->launch($this->workspace, 'Rename', 'Rename the namespace.', $this->packages->all());
    app(Campaigns::class)->cancel($other);

    expect($other->tasks()->pluck('status')->unique()->all())->toBe([TaskStatus::Cancelled])
        ->and($other->refresh()->status)->toBe(CampaignStatus::Cancelled);
});
