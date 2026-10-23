<?php

use App\Actions\ChangeTaskStatus;
use App\Actions\ClaimTasks;
use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Livewire\Board;
use App\Livewire\ShowTask;
use App\Livewire\Tasks;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [$this->root = fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
    $this->beta = Project::query()->where('folder', 'Beta')->sole();
});

it('queues an idea and opens it', function () {
    Livewire::test(Tasks::class)
        ->set('projectId', (string) $this->beta->id)
        ->set('title', 'Webhook retries')
        ->set('body', 'Retry failed webhooks with backoff.')
        ->set('priority', TaskPriority::High->value)
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('tasks.show', Task::query()->sole()));

    expect(Task::query()->sole())
        ->kind->toBe(TaskKind::Idea)->status->toBe(TaskStatus::Queued)->priority->toBe(TaskPriority::High)->spec_id->toBeNull();
});

it('needs a ticket for a ticket task, and a spec of the same project for a spec task', function () {
    $older = Spec::query()->where('key', '2026-10-20-0900-older')->sole();

    Livewire::test(Tasks::class)
        ->set('projectId', (string) $this->beta->id)
        ->set('kind', 'ticket')
        ->set('title', 'Fix it')
        ->call('create')
        ->assertHasErrors(['ticketRef'])
        ->set('kind', 'spec')
        ->set('specId', (string) $older->id)
        ->call('create')
        ->assertHasErrors(['specId']);
});

it('starts a spec task linked, with the status its spec implies, and offers only specs without an open task', function () {
    $import = Spec::query()->where('key', '2026-10-23-1430-import')->sole();

    Livewire::test(Tasks::class)
        ->set('projectId', (string) $this->alpha->id)
        ->set('kind', 'spec')
        ->assertSee('CSV import (needs-decision)')
        ->set('specId', (string) $import->id)
        ->assertSet('title', 'CSV import')
        ->call('create')
        ->assertHasNoErrors();

    expect(Task::query()->sole())->spec_id->toBe($import->id)->status->toBe(TaskStatus::Queued);

    Livewire::test(Tasks::class)->set('projectId', (string) $this->alpha->id)->set('kind', 'spec')->assertDontSee('CSV import (needs-decision)');
});

it('links a task to its spec once the spec appears, and follows the spec from then on', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Exports');
    app(ClaimTasks::class)->claim($task->id, 'beta-1');
    $task->update(['spec_key' => '2026-10-24-0900-exports']); // as link-spec records it

    rewriteFixture($this->root, 'Beta/docs/agent/specs/2026-10-24-0900-exports/meta.yml', "title: Exports\nstatus: building\n");
    app(ScanWorkspaces::class)->ifStale();
    expect($task->fresh())->spec_id->not->toBeNull()->status->toBe(TaskStatus::Running);

    rewriteFixture($this->root, 'Beta/docs/agent/specs/2026-10-24-0900-exports/meta.yml', "title: Exports\nstatus: in-review\n");
    app(ScanWorkspaces::class)->ifStale();
    expect($task->fresh()->status)->toBe(TaskStatus::InReview)
        ->and($task->fresh()->timeline()->pluck('message')->all())->toContain('Linked to spec 2026-10-24-0900-exports', 'Task Claimed → Running', 'Task Running → In review', 'Spec status building → in-review');
});

it('orders the queue by priority, then age, and shows it on the board', function () {
    $first = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Normal and old');
    $this->travel(1)->minutes();
    app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Urgent and new', priority: TaskPriority::Urgent);
    app(ChangeTaskStatus::class)->cancel(app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Cancelled one'));

    expect(Task::query()->inQueueOrder()->pluck('title')->all())->toBe(['Urgent and new', 'Normal and old', 'Cancelled one']);

    expect(Livewire::test(Board::class)->viewData('queue')->pluck('title')->all())->toBe(['Urgent and new', 'Normal and old']);
    $this->get(route('board'))->assertSeeInOrder(['Queue', 'Urgent and new', 'Normal and old']);
    $this->get(route('tasks'))->assertSee('Normal and old')->assertDontSee('Cancelled one');
    $this->get(route('tasks', ['show' => 'done']))->assertSee('Cancelled one');
});

it('edits, cancels and requeues a task, recording each on its timeline', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Ticket, 'Bug', ticketRef: 'BETA-1');

    Livewire::test(ShowTask::class, ['task' => $task])
        ->call('edit')
        ->set('title', 'Bug in exports')
        ->set('priority', TaskPriority::High->value)
        ->call('save')
        ->assertHasNoErrors()
        ->call('cancelTask')
        ->assertSee('Queue again')
        ->call('requeue')
        ->assertSee('Cancel task');

    expect($task->fresh())->title->toBe('Bug in exports')->status->toBe(TaskStatus::Queued)
        ->and($task->timeline()->pluck('message')->all())->toBe(['Queued again', 'Cancelled', 'Edited title, priority', 'Queued Existing ticket: Bug']);
});

it('only cancels open tasks and only requeues cancelled or failed ones', function () {
    $task = app(CreateTask::class)->handle($this->beta, TaskKind::Idea, 'Idea');

    expect(fn () => app(ChangeTaskStatus::class)->requeue($task))->toThrow(ValidationException::class);

    $task->update(['status' => TaskStatus::Done]);

    expect(fn () => app(ChangeTaskStatus::class)->cancel($task))->toThrow(ValidationException::class);
});

it('shows a linked spec\'s open questions on the task, answerable there', function () {
    $task = app(CreateTask::class)->handle($this->alpha, TaskKind::Spec, 'CSV import', spec: Spec::query()->where('key', '2026-10-23-1430-import')->sole());

    $this->get(route('tasks.show', $task))->assertOk()->assertSee('Needs you')->assertSee('Sync or queued?');
});
