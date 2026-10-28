<?php

use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Livewire\ShowBriefing;
use App\Models\Briefing;
use App\Models\Project;
use App\Models\Run;
use App\Models\Spec;
use App\Runner\Runner;
use App\Support\Briefings;
use App\Support\NightShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'ring-master.workspaces' => [$this->root = fixtureWorkspace()],
        'ring-master.runner.claude_bin' => base_path('tests/fixtures/fake-claude'),
        'ring-master.night_shift' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'budget_usd' => 5, 'concurrency' => 4, 'briefing_at' => '07:30'],
    ]);
    putenv('FAKE_CLAUDE_MODE=ok');
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
});

afterEach(fn () => putenv('FAKE_CLAUDE_MODE'));

it('knows when the night window that crosses midnight is on', function () {
    $day = CarbonImmutable::parse('2026-10-28 12:00');

    expect(NightShift::active($day))->toBeFalse()
        ->and(NightShift::startedAt($day->setTime(23, 0))?->format('Y-m-d H:i'))->toBe('2026-10-28 22:00')
        ->and(NightShift::startedAt($day->setTime(3, 0))?->format('Y-m-d H:i'))->toBe('2026-10-27 22:00')
        ->and(NightShift::active($day->setTime(7, 0)))->toBeFalse();
});

it('runs more at once at night and starts nothing once the night budget is spent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-28 23:00'));
    expect(Runner::concurrency())->toBe(4);

    Run::query()->create(['project_id' => $this->alpha->id, 'kind' => RunKind::Task, 'session' => 's', 'status' => RunStatus::Succeeded, 'log_path' => '', 'cost_usd' => 5.5, 'started_at' => now()->subHour(), 'finished_at' => now()]);
    app(CreateTask::class)->handle(Project::query()->where('folder', 'Beta')->sole(), TaskKind::Idea, 'Waits for the morning');

    $runner = app(Runner::class);
    $runner->tick();
    $runner->drain();

    expect(NightShift::exhausted())->toBeTrue()
        ->and(Run::query()->count())->toBe(1);

    // Morning: the window is over, so the task starts.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00'));
    $runner->tick();
    $runner->drain();
    expect(Run::query()->count())->toBe(2);
});

it('makes the morning briefing once, after briefing_at', function () {
    expect(Briefings::due(CarbonImmutable::parse('2026-10-28 07:00')))->toBeFalse()
        ->and(Briefings::due(CarbonImmutable::parse('2026-10-28 08:00')))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-28 08:00'));
    app(Briefings::class)->generate(notify: false);

    expect(Briefings::due())->toBeFalse();
});

it('ranks PRs in review by risk, ticks the low-risk ones and reviews them on GitHub', function () {
    $small = 'https://github.com/acme/alpha/pull/1';
    rewriteFixture($this->root, 'Alpha/docs/agent/specs/2026-10-25-0900-small/meta.yml', "title: Small fix\nstatus: in-review\npr: { url: '{$small}' }\n");
    rewriteFixture($this->root, 'Alpha/docs/agent/specs/2026-10-25-0900-small/brief.md', "Risk: low\n\n## What changed\nA guard.\n");
    rewriteFixture($this->root, 'Beta/docs/agent/specs/2026-10-25-1000-big/meta.yml', "title: Big change\nstatus: in-review\npr: { url: 'https://github.com/acme/beta/pull/2' }\n");
    app(ScanWorkspaces::class)->handle();

    Process::fake(function ($process) use ($small) {
        $command = implode(' ', $process->command);

        return match (true) {
            str_contains($command, "pr view {$small}") => Process::result('{"additions":12,"deletions":3,"files":[{"path":"src/Guard.php"},{"path":"tests/GuardTest.php"}]}'),
            str_contains($command, 'pr view') => Process::result('{"additions":900,"deletions":200,"files":[{"path":"composer.json"},{"path":"src/Big.php"}]}'),
            str_contains($command, 'pr review') => Process::result(errorOutput: 'Can not approve your own pull request', exitCode: 1),
            default => Process::result(),
        };
    });

    $briefing = app(Briefings::class)->generate(notify: false);
    $review = $briefing->data['review'];

    expect(array_column($review, 'title'))->toBe(['Small fix', 'Big change'])
        ->and($review[0]['risk']['level'])->toBe('low')
        ->and($review[1]['risk']['level'])->toBe('high')
        ->and($review[1]['risk']['reasons'])->toContain('touches composer.json')->toContain('no test changes');

    $smallId = Spec::query()->where('title', 'Small fix')->value('id');

    Livewire::test(ShowBriefing::class, ['briefing' => $briefing])
        ->assertSee('Ready for your review')
        ->assertSee('A guard.')
        ->assertSet('ticked', [$smallId])
        ->call('reviewTicked')
        ->assertSee('labelled ring-master:reviewed');

    Process::assertRan(fn ($process) => $process->command === ['gh', 'pr', 'edit', $small, '--add-label', 'ring-master:reviewed']);
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), 'pr merge'));
});

it('brief me now makes a fresh briefing', function () {
    Livewire::test(ShowBriefing::class)->assertSee('No briefing yet')->call('briefNow')->assertSee('Ready for your review');

    expect(Briefing::query()->count())->toBe(1);
});
