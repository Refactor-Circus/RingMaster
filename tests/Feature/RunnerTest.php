<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Livewire\Runs;
use App\Livewire\ShowRun;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Run;
use App\Models\Task;
use App\Runner\ClaudeCommand;
use App\Runner\Runner;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'ring-master.workspaces' => [$this->root = fixtureWorkspace()],
        'ring-master.runner.claude_bin' => base_path('tests/fixtures/fake-claude'),
        'ring-master.runner.concurrency' => 3,
        'ring-master.runner.max_attempts' => 2,
    ]);
    $this->calls = $this->root.'/calls.jsonl';
    putenv('FAKE_CLAUDE_CALLS='.$this->calls);
    putenv('FAKE_CLAUDE_MODE=ok');
    app(ScanWorkspaces::class)->handle();
    $this->beta = Project::query()->where('folder', 'Beta')->sole();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
});

afterEach(function () {
    putenv('FAKE_CLAUDE_MODE');
    putenv('FAKE_CLAUDE_CALLS');
});

/**
 * @return list<array{argv: list<string>, cwd: string}>
 */
function fakeCalls(string $file): array
{
    return is_file($file) ? array_map(fn (string $line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES)) : [];
}

function runOnce(): Runner
{
    $runner = app(Runner::class);
    $runner->tick();
    $runner->drain();

    return $runner;
}

it('ships a queued task in its project folder with only the allowed tools, and records the result', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');

    runOnce();

    $run = Run::query()->sole();
    $call = fakeCalls($this->calls)[0];

    expect($run)->kind->toBe(RunKind::Task)->status->toBe(RunStatus::Succeeded)->cost_usd->toBe('0.0123')->summary->toBe('Finished (ok)')
        ->and($call['cwd'])->toBe($this->beta->path)
        ->and($call['argv'][0])->toBe("/ring-master-inbox --task={$task->id} --session={$run->session}")
        ->and($call['argv'])->toContain('-p', 'stream-json', 'dontAsk', 'none', '--mcp-config', 'Bash(composer *)', 'mcp__ring-master')
        ->and($task->fresh())->status->toBe(TaskStatus::Queued)->attempts->toBe(1)
        ->and($task->timeline()->pluck('message')->all())->toContain("Back in the queue: run #{$run->id} ended without a spec");
});

it('marks a task failed once it has used its attempts', function () {
    putenv('FAKE_CLAUDE_MODE=fail');
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');

    runOnce();
    runOnce();

    expect(Run::query()->pluck('status')->all())->toBe([RunStatus::Failed, RunStatus::Failed])
        ->and($task->fresh())->status->toBe(TaskStatus::Failed)->attempts->toBe(2)
        ->and(runOnce()->busy())->toBeFalse()
        ->and(Run::query()->count())->toBe(2);
});

it('lets the task follow the spec its session created', function () {
    putenv('FAKE_CLAUDE_MODE=spec');
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Fake');
    $task->update(['spec_key' => '2026-10-25-0900-fake']); // what link-spec records

    runOnce();

    expect($task->fresh())->spec_id->not->toBeNull()->status->toBe(TaskStatus::Running);
});

it('applies answers before taking tasks, and does not retry the same answers', function () {
    app(AnswerAttentionItem::class)->handle(AttentionItem::query()->orderBy('id')->first(), 'Per line');
    app(CreateTask::class)->handle($this->alpha, TaskKind::Idea, 'Alpha idea');

    runOnce();

    expect(Run::query()->orderBy('id')->pluck('kind')->all())->toBe([RunKind::Answers])
        ->and(fakeCalls($this->calls)[0]['argv'][0])->toBe('/ring-master-inbox --answers')
        ->and(fakeCalls($this->calls)[0]['cwd'])->toBe($this->alpha->path);

    runOnce();

    expect(Run::query()->orderBy('id')->pluck('kind')->all())->toBe([RunKind::Answers, RunKind::Task]);
});

it('runs one session per folder tree at a time', function () {
    $root = Project::query()->where('is_workspace', true)->sole();
    app(CreateTask::class)->handle($root, TaskKind::Idea, 'Workspace-wide');
    app(CreateTask::class)->handle($this->alpha, TaskKind::Idea, 'Alpha idea');
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Beta idea');

    runOnce();

    expect(Run::query()->with('task')->get()->pluck('task.title')->all())->toBe(['Workspace-wide'])
        ->and(Task::query()->where('status', TaskStatus::Queued)->count())->toBe(3);
});

it('starts nothing while paused', function () {
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    Runner::pause(true);

    runOnce();

    expect(Run::query()->count())->toBe(0);
});

it('stops a run you ask it to stop, and gives its task back', function () {
    putenv('FAKE_CLAUDE_MODE=sleep');
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    $runner = app(Runner::class);
    $runner->tick();

    Livewire::test(Runs::class)->call('stopRun', Run::query()->sole()->id);
    $runner->drain();

    expect(Run::query()->sole()->status)->toBe(RunStatus::Stopped)
        ->and($task->fresh()->status)->toBe(TaskStatus::Queued);
});

it('fails runs a previous runner left behind and frees their tasks', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    $task->update(['status' => TaskStatus::Claimed, 'claimed_by' => 'runner-old', 'attempts' => 1]);
    $run = Run::query()->create(['project_id' => $this->beta->id, 'task_id' => $task->id, 'kind' => RunKind::Task, 'session' => 'runner-old', 'status' => RunStatus::Running, 'log_path' => '', 'started_at' => now()]);

    app(Runner::class)->recoverOrphans();

    expect($run->fresh())->status->toBe(RunStatus::Failed)->summary->toBe('The runner stopped while this run was going.')
        ->and($task->fresh()->status)->toBe(TaskStatus::Queued);
});

it('passes the model and a budget cap when configured', function () {
    config(['ring-master.runner.model' => 'sonnet', 'ring-master.runner.max_budget_usd' => '2.50']);
    $run = Run::query()->create(['project_id' => $this->beta->id, 'kind' => RunKind::Answers, 'session' => 's', 'log_path' => '']);

    expect(app(ClaudeCommand::class)->for($run))->toContain('--model', 'sonnet', '--max-budget-usd', '2.50');
});

it('shows the runner, its runs and a run\'s log', function () {
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    runOnce();
    $run = Run::query()->sole();

    Livewire::test(Runs::class)
        ->assertSee('Running')
        ->assertSee('Exports')
        ->assertSee('$0.0123')
        ->call('togglePause')
        ->assertSee('Resume runner');

    Livewire::test(ShowRun::class, ['run' => $run])
        ->assertSee('Working on it')
        ->assertSee('Bash composer test')
        ->assertSee('Finished (ok)');
});

it('starts each session in a process session of its own, out of reach of the runner\'s Ctrl+C', function () {
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');

    runOnce();

    expect(fakeCalls($this->calls)[0]['sid'])->not->toBeNull()->not->toBe(posix_getsid(0));
})->skip(! function_exists('posix_getsid'), 'needs posix');

it('stops every session on a second Ctrl+C', function () {
    putenv('FAKE_CLAUDE_MODE=sleep');
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    $runner = app(Runner::class);
    $runner->tick();
    $runner->stop();

    $runner->terminateAll();
    $runner->drain();

    expect(Run::query()->sole()->status)->toBe(RunStatus::Stopped);
});

it('adopts a session a previous runner left running, and settles it when it ends', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    $task->update(['status' => TaskStatus::Claimed, 'claimed_by' => 'runner-old', 'attempts' => 1]);
    $process = proc_open(['sleep', '1'], [], $pipes);
    $run = Run::query()->create(['project_id' => $this->beta->id, 'task_id' => $task->id, 'kind' => RunKind::Task, 'session' => 'runner-old', 'status' => RunStatus::Running, 'pid' => proc_get_status($process)['pid'], 'log_path' => '', 'started_at' => now()]);

    $runner = app(Runner::class);
    $runner->recoverOrphans();

    expect($runner->busy())->toBeTrue()->and($run->fresh()->status)->toBe(RunStatus::Running);

    proc_close($process);
    $runner->drain();

    expect($runner->busy())->toBeFalse()
        ->and($run->fresh()->status)->toBe(RunStatus::Failed)
        ->and($task->fresh()->status)->toBe(TaskStatus::Queued);
})->skip(! function_exists('posix_kill'), 'needs posix');
