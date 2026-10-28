<?php

use App\Actions\Improvements;
use App\Actions\ScanWorkspaces;
use App\Enums\ImprovementStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Livewire\Factory;
use App\Mcp\Servers\RingMasterServer;
use App\Mcp\Tools\ProposeImprovement;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Run;
use App\Models\Spec;
use App\Models\Task;
use App\Runner\ClaudeCommand;
use App\Runner\Runner;
use App\Support\Improver;
use App\Support\Replays;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $root = fixtureFolder([
        'ring-master.yml' => "workspace:\n  packages: [Alpha, Ringside]\n",
        'composer.json' => '{"name": "acme/workspace"}',
        'Alpha/composer.json' => '{"name": "acme/alpha"}',
        'Alpha/docs/agent/specs/2026-10-20-0900-export/meta.yml' => "title: Export\nstatus: merged\n",
        'Alpha/docs/agent/specs/2026-10-20-0900-export/tasks.md' => "## Group 1\n- [x] 1.1 Tests\n",
        'Alpha/docs/agent/specs/2026-10-20-0900-export/round-1/gate.md' => "VERDICT: PASS\n",
        'Alpha/docs/agent/specs/2026-10-20-0900-export/round-2/gate.md' => "VERDICT: PASS\n",
        'Ringside/composer.json' => '{"name": "refactor-circus/ringside"}',
    ]);
    config(['ring-master.workspaces' => [$root]]);
    app(ScanWorkspaces::class)->handle();
    $this->root = Project::query()->where('is_workspace', true)->sole();
    $this->analyst = Run::query()->create(['project_id' => $this->root->id, 'kind' => RunKind::Improve, 'session' => 'analyst-1', 'status' => RunStatus::Running, 'log_path' => '', 'started_at' => now()]);
});

function proposeImprovement(string $title = 'Split compound cd commands', string $target = 'ringside'): Improvement
{
    return app(Improvements::class)->propose('analyst-1', $title, $target, 'The prepare agent keeps running cd && composer.', 'runs #3, #7: refused', 'In factory.md, tell the prepare agent to use --working-dir.');
}

it('lets only a running analyst propose, and refuses duplicates', function () {
    RingMasterServer::tool(ProposeImprovement::class, ['session' => 'analyst-1', 'title' => 'Split compound cd commands', 'target' => 'ringside', 'rationale' => 'r', 'evidence' => 'e', 'change' => 'c'])->assertOk();
    RingMasterServer::tool(ProposeImprovement::class, ['session' => 'analyst-1', 'title' => 'split compound CD commands', 'target' => 'ringside', 'rationale' => 'r', 'evidence' => 'e', 'change' => 'c'])->assertHasErrors();
    RingMasterServer::tool(ProposeImprovement::class, ['session' => 'nobody', 'title' => 'Other', 'target' => 'ringside', 'rationale' => 'r', 'evidence' => 'e', 'change' => 'c'])->assertHasErrors();

    expect(Improvement::query()->count())->toBe(1);
});

it('gives the analyst the evidence and where the factory lives', function () {
    $prompt = app(Improver::class)->prompt($this->analyst);

    expect($prompt)->toContain('analyst-1')->toContain('/Ringside/resources')->toContain('Review rounds per job: Alpha/2026-10-20-0900-export 2')->toContain('propose-improvement');
});

it('builds an accepted Ringside change as a task in the Ringside checkout, then picks up its branch', function () {
    $improvement = proposeImprovement();
    app(Improvements::class)->accept($improvement);

    $task = Task::query()->sole();
    expect($task->project->folder)->toBe('Ringside')
        ->and($task->title)->toBe('Factory improvement: Split compound cd commands')
        ->and($task->body)->toContain('Never merge it')
        ->and($improvement->refresh()->status)->toBe(ImprovementStatus::Building);

    $spec = Spec::query()->create(['project_id' => $task->project_id, 'key' => 'k', 'dir' => 'docs/agent/specs/k', 'title' => 'k', 'status' => 'in-review', 'column' => 'In review', 'branch' => 'ring-master/k', 'packages' => [], 'rounds' => [], 'files' => []]);
    $task->update(['spec_id' => $spec->id]);
    app(Improvements::class)->track();

    expect($improvement->refresh()->branch)->toBe('ring-master/k');
});

it('adopts a RingMaster setting change straight away: you make it', function () {
    expect(app(Improvements::class)->accept(proposeImprovement('Allow rg', 'allowlist'))->status)->toBe(ImprovementStatus::Adopted)
        ->and(Task::query()->count())->toBe(0);
});

it('evaluates by replaying recent jobs, then compares each with how it went first', function () {
    $improvement = proposeImprovement();
    $improvement->update(['status' => ImprovementStatus::Building, 'branch' => 'ring-master/k']);

    app(Improvements::class)->evaluate($improvement);
    expect($improvement->refresh()->status)->toBe(ImprovementStatus::Evaluating)
        ->and($improvement->results['pending'])->toHaveCount(1);

    $alpha = Project::query()->where('folder', 'Alpha')->sole();
    $replay = Run::query()->create(['project_id' => $alpha->id, 'improvement_id' => $improvement->id, 'replay_of' => '2026-10-20-0900-export', 'kind' => RunKind::Replay, 'session' => 'r', 'status' => RunStatus::Succeeded, 'log_path' => '', 'cost_usd' => 1.25, 'started_at' => now()]);
    expect(app(ClaudeCommand::class)->for($replay))->toContain('/ring-master-factory 2026-10-20-0900-export --replay')
        ->and(implode(' ', app(ClaudeCommand::class)->for($replay)))->toContain('--max-budget-usd '.(20 / 3));

    File::ensureDirectoryExists(Replays::dir($replay).'/Alpha/docs/agent/specs/2026-10-20-0900-export');
    File::put(Replays::dir($replay).'/Alpha/docs/agent/specs/2026-10-20-0900-export/replay.json', '{"status":"approved","rounds":1,"gate_red_rounds":0,"findings_left":0,"nits":1,"reasons":[]}');
    $improvement->update(['results' => [...$improvement->results, 'pending' => []]]);

    app(Replays::class)->finish($replay);

    $row = $improvement->refresh()->results['specs']['2026-10-20-0900-export'];
    expect($improvement->status)->toBe(ImprovementStatus::Evaluated)
        ->and($row['replay'])->toMatchArray(['status' => 'approved', 'rounds' => 1, 'cost' => 1.25])
        ->and($row['baseline'])->toMatchArray(['rounds' => 2, 'escalated' => false])
        ->and(is_dir(Replays::dir($replay)))->toBeFalse();

    Livewire::test(Factory::class)->assertSee('replays against ring-master/k')->assertSee('vs 2')->assertSee('Adopt');
});

it('asks for an analyst run from the Factory page', function () {
    $this->analyst->update(['status' => RunStatus::Succeeded, 'started_at' => now()->subDay()]);
    Livewire::test(Factory::class)->call('analyse');

    expect(app(Improver::class)->due())->toHaveCount(1);
});

it('says why a requested analyst run has not started', function () {
    $this->analyst->update(['status' => RunStatus::Succeeded, 'started_at' => now()->subDay()]);
    Livewire::test(Factory::class)->call('analyse')
        ->assertSee('Analysis requested')
        ->assertSee('No runner is running');

    Cache::put(Runner::HEARTBEAT, now()->getTimestamp());
    Livewire::test(Factory::class)->assertSee('Requested: the runner starts the analyst');

    $this->analyst->update(['status' => RunStatus::Running, 'kind' => RunKind::Task]);
    Livewire::test(Factory::class)->assertSee("Waiting for run #{$this->analyst->id}");
});

it('records a replay whose checkout cannot be built, and still finishes the evaluation', function () {
    config(['ring-master.runner.claude_bin' => base_path('tests/fixtures/fake-claude')]);
    $improvement = proposeImprovement();
    $this->analyst->update(['status' => RunStatus::Succeeded]);
    $improvement->update(['status' => ImprovementStatus::Building, 'branch' => 'ring-master/k']);
    app(Improvements::class)->evaluate($improvement);

    // Neither folder is a git repository, so setting up the checkout fails.
    $runner = app(Runner::class);
    $runner->tick();
    $runner->drain();

    $run = Run::query()->where('kind', RunKind::Replay)->sole();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->summary)->toStartWith('Could not set up the replay')
        ->and($improvement->refresh()->status)->toBe(ImprovementStatus::Evaluated)
        ->and($improvement->results['specs']['2026-10-20-0900-export']['replay']['status'])->toBe('no result');
});
