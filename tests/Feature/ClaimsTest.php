<?php

use App\Actions\AnswerAttentionItem;
use App\Actions\ClaimTasks;
use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Mcp\Servers\RingMasterServer;
use App\Mcp\Tools\ClaimTask;
use App\Mcp\Tools\Heartbeat;
use App\Mcp\Tools\LinkSpec;
use App\Mcp\Tools\NextTask;
use App\Mcp\Tools\PendingAnswers;
use App\Mcp\Tools\QueueStatus;
use App\Mcp\Tools\ReleaseTask;
use App\Models\AttentionItem;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()], 'ring-master.claims.lease_seconds' => 600]);
    app(ScanWorkspaces::class)->handle();
    $this->beta = Project::query()->where('folder', 'Beta')->sole();
    $this->task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports', 'CSV exports of every table.', priority: TaskPriority::High);
});

it('offers the next task in a folder, a workspace root covering its packages', function () {
    $alpha = Project::query()->where('folder', 'Alpha')->sole();
    app(CreateTask::class)->handle($alpha, TaskKind::Idea, 'Alpha idea');

    expect(app(ClaimTasks::class)->next($this->root)?->title)->toBe('Exports')
        ->and(app(ClaimTasks::class)->next($alpha->path)?->title)->toBe('Alpha idea')
        ->and(app(ClaimTasks::class)->next('/somewhere/else'))->toBeNull();
});

it('lets one session claim a task, and no other until the lease runs out', function () {
    $claims = app(ClaimTasks::class);
    $claims->claim($this->task->id, 'beta-1');

    expect($this->task->fresh())->status->toBe(TaskStatus::Claimed)->claimed_by->toBe('beta-1')
        ->and($claims->next($this->beta->path))->toBeNull()
        ->and(fn () => $claims->claim($this->task->id, 'beta-2'))->toThrow(ValidationException::class);

    $this->travel(11)->minutes();

    expect($claims->claim($this->task->id, 'beta-2')->claimed_by)->toBe('beta-2');
});

it('keeps a claim alive with heartbeats, and only for the session that holds it', function () {
    $claims = app(ClaimTasks::class);
    $claims->claim($this->task->id, 'beta-1');
    $this->travel(8)->minutes();
    $claims->heartbeat($this->task->id, 'beta-1', 'Spec review done');
    $this->travel(8)->minutes();

    expect($claims->expire())->toBe(0)
        ->and($this->task->timeline()->pluck('message')->all())->toContain('Spec review done')
        ->and(fn () => $claims->heartbeat($this->task->id, 'someone-else'))->toThrow(ValidationException::class);
});

it('returns a task to the queue when its claim expires before a spec exists', function () {
    app(ClaimTasks::class)->claim($this->task->id, 'beta-1');
    $this->travel(11)->minutes();

    app(ScanWorkspaces::class)->handle();

    expect($this->task->fresh())->status->toBe(TaskStatus::Queued)->claimed_by->toBeNull()
        ->and($this->task->timeline()->pluck('message')->first())->toBe('Claim expired; back in the queue');
});

it('links the spec a session created, after which the task follows it', function () {
    $claims = app(ClaimTasks::class);
    $claims->claim($this->task->id, 'beta-1');
    rewriteFixture($this->root, 'Beta/docs/agent/specs/2026-10-24-0900-exports/meta.yml', "title: Exports\nstatus: shaping\n");

    $task = $claims->linkSpec($this->task->id, 'beta-1', 'docs/agent/specs/2026-10-24-0900-exports/');

    expect($task)->spec_key->toBe('2026-10-24-0900-exports')->spec_id->not->toBeNull()->status->toBe(TaskStatus::Running);

    $this->travel(30)->minutes();
    app(ScanWorkspaces::class)->handle();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

it('releases a task back to the queue, or fails it', function () {
    $claims = app(ClaimTasks::class);
    $claims->claim($this->task->id, 'beta-1');
    $claims->release($this->task->id, 'beta-1', 'Session ending');

    expect($this->task->fresh())->status->toBe(TaskStatus::Queued)->claimed_by->toBeNull();

    $claims->claim($this->task->id, 'beta-1');
    $claims->release($this->task->id, 'beta-1', 'Needs a database Beta does not have', failed: true);

    expect($this->task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($this->task->timeline()->pluck('message')->first())->toBe('Failed: Needs a database Beta does not have');
});

it('serves the whole loop over MCP', function () {
    RingMasterServer::tool(QueueStatus::class, ['project_path' => $this->root])
        ->assertOk()
        ->assertSee(['"queued":1', '"needs_user":2', 'Exports']);

    RingMasterServer::tool(NextTask::class, ['project_path' => $this->beta->path])
        ->assertOk()
        ->assertSee(['"id":'.$this->task->id, 'CSV exports of every table.']);

    RingMasterServer::tool(ClaimTask::class, ['task_id' => $this->task->id, 'session' => 'beta-1'])->assertOk()->assertSee('"status":"claimed"');
    RingMasterServer::tool(ClaimTask::class, ['task_id' => $this->task->id, 'session' => 'beta-2'])->assertHasErrors();
    RingMasterServer::tool(Heartbeat::class, ['task_id' => $this->task->id, 'session' => 'beta-1', 'message' => 'Shaping'])->assertOk();

    rewriteFixture($this->root, 'Beta/docs/agent/specs/2026-10-24-0900-exports/meta.yml', "title: Exports\nstatus: written\n");
    RingMasterServer::tool(LinkSpec::class, ['task_id' => $this->task->id, 'session' => 'beta-1', 'spec' => '2026-10-24-0900-exports'])
        ->assertOk()
        ->assertSee(['"key":"2026-10-24-0900-exports"', '"ship_input":"2026-10-24-0900-exports"']);

    RingMasterServer::tool(ReleaseTask::class, ['task_id' => $this->task->id, 'session' => 'beta-1', 'reason' => 'Done for today'])->assertOk();

    expect(Task::query()->find($this->task->id)?->status)->toBe(TaskStatus::Running);
});

it('lists the answers waiting to be applied, by spec', function () {
    $item = AttentionItem::query()->orderBy('id')->first();
    app(AnswerAttentionItem::class)->handle($item, 'Per line', 'Imports are line based.');

    RingMasterServer::tool(PendingAnswers::class, ['project_path' => $this->root])
        ->assertOk()
        ->assertSee(['"spec":"2026-10-23-1430-import"', '"answer":"Per line"', '"note":"Imports are line based."', '"source":"build"'])
        ->assertDontSee('Sync or queued?');

    RingMasterServer::tool(PendingAnswers::class, ['project_path' => $this->beta->path])->assertOk()->assertSee('"specs":[]');
});

it('prints one line for a session, and nothing when there is nothing to do', function () {
    $this->artisan('ring-master:summary', ['--project' => $this->root])
        ->expectsOutput('RingMaster: 1 queued, 2 need you, 0 answer(s) to apply. Run /ring-master-inbox to work through them.')
        ->assertSuccessful();

    $this->artisan('ring-master:summary', ['--project' => '/somewhere/else'])->doesntExpectOutputToContain('RingMaster')->assertSuccessful();
});
