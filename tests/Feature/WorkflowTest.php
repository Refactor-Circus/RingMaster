<?php

use App\Actions\ScanWorkspaces;
use App\Enums\RunFailure;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Livewire\Workflow;
use App\Models\Project;
use App\Models\Run;
use App\Support\WorkflowMap;
use Livewire\Livewire;

beforeEach(function () {
    $this->root = fixtureWorkspace();
    config(['ring-master.workspaces' => [$this->root]]);
    app(ScanWorkspaces::class)->handle();
});

it('shows every stage in order, the factory loop and how a run ends', function () {
    $this->get(route('workflow'))
        ->assertOk()
        ->assertSeeInOrder(['Ticket', 'Shape', 'Write spec', 'Spec review', 'Tasks', 'Factory', 'PR review', 'Post-mortem'])
        ->assertSeeInOrder(['Prepare worktree', 'Build groups', 'Gate', 'Reviewers', 'Rework'])
        ->assertSeeInOrder(['PR opened', 'Needs you', 'Escalated'])
        ->assertSee('Merges duplicate findings')
        ->assertDontSee('turn on: roles: { triage: { enabled: true } }')
        ->assertSee('/ring-master-spec-review')
        ->assertSee('code · sonnet, security · sonnet, standards · sonnet, compat · sonnet');
});

it('describes every part of the workflow and names its model', function () {
    $map = WorkflowMap::load(Project::query()->where('folder', 'Alpha')->sole()->path);
    $models = fn (array $facts) => collect($facts)->filter(fn (array $f) => str_ends_with($f['label'], 'model'))->pluck('value');
    $factory = $map->factory();

    foreach ([...$map->stages(), ...$factory['once'], ...$factory['loop'], ...$factory['after']] as $part) {
        expect($part['does'])->not->toBeEmpty();

        if ($part['key'] !== 'pr') {
            expect($models($part['facts']))->not->toBeEmpty()->not->toContain('session model');
        }
    }

    expect($models(collect($map->stages())->firstWhere('key', 'shape')['facts'])->all())->toBe(['opus'])
        ->and($models($factory['once'][1]['facts'])->all())->toBe(['opus · high effort']);
});

it('reads the chosen project\'s ring-master.yml and marks what it sets', function () {
    file_put_contents($this->root.'/Alpha/ring-master.yml', "stages:\n  ticket: false\nreview:\n  reviewers: [code, ui]\n  rounds: { max: 5 }\n");
    $alpha = Project::query()->where('folder', 'Alpha')->sole();
    $map = WorkflowMap::load($alpha->path);

    expect(collect($map->stages())->firstWhere('key', 'ticket')['on'])->toBeFalse()
        ->and($map->set('review.rounds.max'))->toBeTrue()
        ->and($map->set('review.rounds.min'))->toBeFalse()
        ->and(collect($map->exits())->firstWhere('key', 'escalated')['when'])->toContain('findings left after 5 rounds');

    Livewire::test(Workflow::class)
        ->set('project', (string) $alpha->id)
        ->assertSee('code · sonnet, ui · sonnet')
        ->assertSee('max 5');
});

it('counts the jobs waiting at each stage', function () {
    $alpha = Project::query()->where('folder', 'Alpha')->sole();

    // The fixture's CSV import job is needs-decision; the older one is closed.
    Livewire::test(Workflow::class)
        ->set('project', (string) $alpha->id)
        ->assertViewHas('counts', fn (array $counts) => $counts['needs-decision'] === 1 && $counts['closed'] === 1);
});

it('says how to turn back on what ring-master.yml turns off', function () {
    file_put_contents($this->root.'/Alpha/ring-master.yml', "build:\n  parallel: false\nroles:\n  triage: { enabled: false }\n");

    Livewire::test(Workflow::class, ['project' => (string) Project::query()->where('folder', 'Alpha')->sole()->id])
        ->assertSee('one at a time')
        ->assertSee('turn on: build: { parallel: true }')
        ->assertSee('turn on: roles: { triage: { enabled: true } }');
});

it('says when a project has no ring-master.yml', function () {
    $gamma = Project::query()->where('folder', 'Beta')->sole();

    Livewire::test(Workflow::class)
        ->set('project', (string) $gamma->id)
        ->assertSee('not found: all defaults');
});

it('shows the parallel integrate step, the impact gate, model escalation and last week\'s numbers', function () {
    $alpha = Project::query()->where('folder', 'Alpha')->sole();
    Run::query()->create(['project_id' => $alpha->id, 'kind' => RunKind::Task, 'session' => 's1', 'status' => RunStatus::Succeeded, 'log_path' => '', 'cost_usd' => 1.5, 'started_at' => now()->subMinutes(10), 'finished_at' => now()]);
    Run::query()->create(['project_id' => $alpha->id, 'kind' => RunKind::Task, 'session' => 's2', 'status' => RunStatus::Failed, 'failure' => RunFailure::Limit, 'log_path' => '', 'started_at' => now()->subMinutes(5), 'finished_at' => now()]);

    Livewire::test(Workflow::class, ['project' => (string) $alpha->id])
        ->assertSeeInOrder(['Build groups', 'Integrate', 'Gate', 'Rework', 'Impact gate'])
        ->assertDontSee('turn on: build: { parallel: true }')
        ->assertSee('in parallel, up to 2')
        ->assertSee('sonnet · low effort → opus on retry')
        ->assertSee('Last 7 days')
        ->assertSee('50%')
        ->assertSee('$1.50')
        ->assertSee('usage limit ×1');
});
