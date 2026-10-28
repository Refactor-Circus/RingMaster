<?php

use App\Actions\ScanWorkspaces;
use App\Enums\SignalKind;
use App\Models\Project;
use App\Models\Run;
use App\Models\Signal;
use App\Support\Planner;
use App\Support\Signals;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
    mkdir($this->alpha->path.'/.git');
});

function fakeGitHub(array $issues): void
{
    Process::fake(function ($process) use ($issues) {
        $command = implode(' ', $process->command);

        return match (true) {
            str_contains($command, 'remote.origin.url') => Process::result("git@github.com:acme/alpha.git\n"),
            str_contains($command, 'issue list') => Process::result(json_encode($issues)),
            str_contains($command, 'defaultBranchRef') => Process::result("main\n"),
            str_contains($command, 'run list') => Process::result(json_encode([
                ['workflowName' => 'tests', 'conclusion' => 'success', 'headBranch' => 'main', 'url' => 'u1'],
                ['workflowName' => 'tests', 'conclusion' => 'failure', 'headBranch' => 'main', 'url' => 'u2'],
                ['workflowName' => 'tests', 'conclusion' => 'success', 'headBranch' => 'main', 'url' => 'u3'],
                ['workflowName' => 'tests', 'conclusion' => 'success', 'headBranch' => 'main', 'url' => 'u4'],
            ])),
            str_contains($command, 'pr list') => Process::result(json_encode([['number' => 9, 'title' => 'Bump x', 'url' => 'pr9', 'createdAt' => now()->subDays(10)->toIso8601String()]])),
            default => Process::result(),
        };
    });
}

it('reads open issues, flaky checks and stale Dependabot PRs, and resolves what is gone', function () {
    fakeGitHub([['number' => 4, 'title' => 'Export crashes', 'url' => 'https://github.com/acme/alpha/issues/4', 'labels' => [['name' => 'bug']]]]);

    expect(app(Signals::class)->collect())->toBe(3)
        ->and(Signal::query()->where('kind', SignalKind::Flaky)->sole()->detail)->toBe('1 of the last 4 runs on main failed')
        ->and(Signal::query()->where('kind', SignalKind::Issue)->sole())->title->toBe('#4 Export crashes')->detail->toBe('labels: bug');

    fakeGitHub([]);
    app(Signals::class)->collect();

    expect(Signal::query()->where('kind', SignalKind::Issue)->sole()->status)->toBe('resolved');
});

it('keeps signals when gh fails, and hands open ones to the planner', function () {
    fakeGitHub([['number' => 4, 'title' => 'Export crashes', 'url' => 'https://github.com/acme/alpha/issues/4', 'labels' => []]]);
    app(Signals::class)->collect();

    Process::fake(fn ($process) => str_contains(implode(' ', $process->command), 'remote.origin.url') ? Process::result("git@github.com:acme/alpha.git\n") : Process::result(exitCode: 1));
    app(Signals::class)->collect();
    expect(Signal::query()->where('status', 'open')->count())->toBe(3);

    $root = Project::query()->where('is_workspace', true)->sole();
    $run = Run::query()->create(['project_id' => $root->id, 'kind' => 'plan', 'session' => 'plan-1', 'status' => 'running', 'log_path' => '', 'started_at' => now()]);

    expect(app(Planner::class)->prompt($run))->toContain('Alpha (issue): #4 Export crashes https://github.com/acme/alpha/issues/4')->toContain('cite its URL');
});
