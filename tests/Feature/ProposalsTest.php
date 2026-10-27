<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ChangeTaskStatus;
use App\Actions\ClaimTasks;
use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskProposals;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Livewire\Inbox;
use App\Livewire\ShowTask;
use App\Mcp\Servers\RingMasterServer;
use App\Mcp\Tools\ClaimTask;
use App\Mcp\Tools\ProposeTask;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use App\Support\PullRequests;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
    $this->beta = Project::query()->where('folder', 'Beta')->sole();
    $this->task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    app(ClaimTasks::class)->claim($this->task->id, 'beta-1');
});

function propose(Task $task): Task
{
    return app(TaskProposals::class)->propose($task->id, 'beta-1', 'Alpha', 'Drop unserialize', 'Pass search results as JSON.', 'The gate fails on unserialize in Alpha.');
}

it('lets a session propose a follow-up its task waits on, which nothing runs until you accept it', function () {
    RingMasterServer::tool(ProposeTask::class, ['task_id' => $this->task->id, 'session' => 'beta-1', 'project' => 'Alpha', 'title' => 'Drop unserialize', 'body' => 'Pass search results as JSON.', 'reason' => 'The gate fails on unserialize in Alpha.'])
        ->assertOk()
        ->assertSee('"status":"proposed"');

    $proposal = Task::query()->where('status', TaskStatus::Proposed)->sole();
    app(ClaimTasks::class)->release($this->task->id, 'beta-1', 'Waiting on Alpha.');

    expect($proposal->project_id)->toBe($this->alpha->id)
        ->and($this->task->fresh()->blocked_by_task_id)->toBe($proposal->id)
        ->and($this->task->fresh()->statusLabel())->toBe('Blocked')
        ->and(app(ClaimTasks::class)->claimable($proposal->id))->toBeFalse()
        ->and(app(ClaimTasks::class)->claimable($this->task->id))->toBeFalse();

    Livewire::test(Inbox::class)->assertSee('Drop unserialize')->assertSee('The gate fails on unserialize in Alpha.')->call('acceptProposal', $proposal->id);

    expect($proposal->fresh()->status)->toBe(TaskStatus::Queued)
        ->and(app(ClaimTasks::class)->claimable($proposal->id))->toBeTrue()
        ->and(app(ClaimTasks::class)->claimable($this->task->id))->toBeFalse();
});

it('lets a session applying answers propose for an unclaimed task of its spec', function () {
    app(ClaimTasks::class)->release($this->task->id, 'beta-1', 'Stopped.');

    $proposal = app(TaskProposals::class)->propose($this->task->id, 'answers-1', 'Alpha', 'Drop unserialize', 'b', 'r');

    expect($this->task->fresh()->blocked_by_task_id)->toBe($proposal->id);
});

it('refuses a proposal from a session that does not hold the task, or for an unknown project', function () {
    expect(fn () => app(TaskProposals::class)->propose($this->task->id, 'someone-else', 'Alpha', 't', 'b', 'r'))->toThrow(ValidationException::class)
        ->and(fn () => app(TaskProposals::class)->propose($this->task->id, 'beta-1', 'Nowhere', 't', 'b', 'r'))->toThrow(ValidationException::class);
});

it('resumes the waiting task with a note once its blocker is done', function () {
    $proposal = propose($this->task);
    app(ClaimTasks::class)->release($this->task->id, 'beta-1', 'Waiting on Alpha.');
    app(TaskProposals::class)->accept($proposal);

    expect(app(TaskProposals::class)->unblockFinished())->toBe(0);

    $proposal->update(['status' => TaskStatus::Done]);

    expect(app(TaskProposals::class)->unblockFinished())->toBe(1)
        ->and($this->task->fresh())->isBlocked()->toBeFalse()->isPaused()->toBeFalse()
        ->and($this->task->fresh()->resume_note)->toContain("#{$proposal->id}")->toContain('composer update acme/alpha')
        ->and(app(ClaimTasks::class)->claimable($this->task->id))->toBeTrue();

    RingMasterServer::tool(ClaimTask::class, ['task_id' => $this->task->id, 'session' => 'beta-2'])->assertOk()->assertSee('never composer local:update');
});

it('resumes the waiting task once the blocker\'s GitHub PR is merged', function () {
    Process::fake(['*' => Process::result("MERGED\n")]);
    $proposal = propose($this->task);
    app(TaskProposals::class)->accept($proposal);
    $spec = Spec::query()->where('project_id', $this->alpha->id)->first();
    $spec->update(['status' => 'in-review', 'pr' => ['number' => 3, 'url' => 'https://github.com/acme/alpha/pull/3']]);
    $proposal->update(['status' => TaskStatus::InReview, 'spec_id' => $spec->id]);

    expect(app(TaskProposals::class)->unblockFinished())->toBe(0)
        ->and(app(PullRequests::class)->refresh())->toBe(1)
        ->and(app(TaskProposals::class)->unblockFinished())->toBe(1);

    Process::assertRan(fn ($process) => $process->command === ['gh', 'pr', 'view', 'https://github.com/acme/alpha/pull/3', '--json', 'state', '--jq', '.state']);
});

it('hands a task in review back to a session once its PR is merged', function () {
    $state = 'OPEN';
    Process::fake(function ($process) use (&$state) {
        return Process::result(str_contains((string) json_encode($process->command), 'checks') ? '[]' : $state."\n");
    });
    $spec = Spec::query()->where('project_id', $this->alpha->id)->first();
    $spec->update(['status' => 'in-review', 'pr' => ['url' => 'https://github.com/acme/alpha/pull/3']]);
    $task = app(CreateTask::class)->handle($this->alpha, TaskKind::Idea, 'Search');
    $task->update(['status' => TaskStatus::InReview, 'spec_id' => $spec->id, 'claimed_by' => 'old', 'lease_expires_at' => now()->subHour()]);

    expect(app(PullRequests::class)->refresh())->toBe(0)
        ->and(app(ClaimTasks::class)->claimable($task->id))->toBeFalse();

    $state = 'MERGED';

    expect(app(PullRequests::class)->refresh())->toBe(1)
        ->and(app(ClaimTasks::class)->claimable($task->id))->toBeTrue();
});

it('keeps the waiting task paused when you decline the proposal', function () {
    $proposal = propose($this->task);
    app(ClaimTasks::class)->release($this->task->id, 'beta-1', 'Waiting on Alpha.');

    Livewire::test(ShowTask::class, ['task' => $proposal])->assertSee('Proposed by a session')->call('declineProposal');

    expect($proposal->fresh()->status)->toBe(TaskStatus::Cancelled)
        ->and($this->task->fresh())->isBlocked()->toBeFalse()->isPaused()->toBeTrue()
        ->and($this->task->fresh()->pause_reason)->toContain('You declined');
});

it('lets you resume a blocked task yourself, dropping the wait', function () {
    propose($this->task);
    app(ClaimTasks::class)->release($this->task->id, 'beta-1', 'Waiting on Alpha.');

    Livewire::test(ShowTask::class, ['task' => $this->task])->assertSee('Waiting on another task')->call('resume');

    expect($this->task->fresh()->isBlocked())->toBeFalse();
    expect(fn () => app(ChangeTaskStatus::class)->resume($this->task->fresh()))->toThrow(ValidationException::class);
});

it('raises failing PR checks in the Inbox, closes them when they pass, and leaving them needs no session', function () {
    $checks = '[{"bucket":"fail","link":"https://github.com/acme/alpha/actions/runs/1/job/2","name":"P8.5 - ubuntu","workflow":"tests"},{"bucket":"pass","link":"x","name":"Pint","workflow":"lint"}]';
    Process::fake(function ($process) use (&$checks) {
        return Process::result(str_contains((string) json_encode($process->command), 'checks') ? $checks : "OPEN\n", exitCode: 1);
    });
    $spec = Spec::query()->where('project_id', $this->alpha->id)->first();
    $spec->update(['status' => 'in-review', 'pr' => ['url' => 'https://github.com/acme/alpha/pull/3']]);
    // Keeps the spec in review when the scan below reads its folder.
    rewriteFixture($this->root, 'Alpha/'.$spec->dir.'/meta.yml', "title: x\nstatus: in-review\npr: https://github.com/acme/alpha/pull/3\n");

    app(PullRequests::class)->refresh();
    app(PullRequests::class)->refresh();
    app(ScanWorkspaces::class)->handle();

    $item = $spec->attentionItems()->where('source', 'ci')->sole();
    expect($item)->status->toBe(AttentionStatus::Open)->question->toBe('1 PR check fails. Send the job back to fix them?')
        ->and($item->evidence)->toContain('tests / P8.5 - ubuntu: https://github.com/acme/alpha/actions/runs/1/job/2')->not->toContain('Pint')
        ->and($item->options)->toBe([AttentionKind::REWORK, AttentionKind::LEAVE])
        ->and($item->note)->toContain('--log-failed');

    $checks = '[{"bucket":"pass","link":"x","name":"P8.5 - ubuntu","workflow":"tests"}]';
    app(PullRequests::class)->refresh();
    expect($item->fresh()->status)->toBe(AttentionStatus::Closed);

    $checks = '[{"bucket":"fail","link":"https://github.com/acme/alpha/actions/runs/5/job/6","name":"P8.5 - ubuntu","workflow":"tests"}]';
    app(PullRequests::class)->refresh();
    $again = $spec->attentionItems()->where('source', 'ci')->where('status', AttentionStatus::Open)->sole();
    app(AnswerAttentionItem::class)->handle($again, AttentionKind::LEAVE);

    expect($again->fresh()->status)->toBe(AttentionStatus::Closed);
});
