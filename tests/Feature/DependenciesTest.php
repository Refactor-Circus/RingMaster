<?php

use App\Actions\CreateTask;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskDependencies;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Livewire\ShowTask;
use App\Livewire\Tasks;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['ring-master.workspaces' => [fixtureWorkspace()]]);
    app(ScanWorkspaces::class)->handle();
    $this->alpha = Project::query()->where('folder', 'Alpha')->sole();
    $this->beta = Project::query()->where('folder', 'Beta')->sole();
    $this->create = fn (Project $project, string $title, array $dependsOn = []): Task => app(CreateTask::class)->handle($project, TaskKind::Idea, $title, dependsOn: $dependsOn);
});

it('waits until every task it depends on is done, then resumes with a note naming them all', function () {
    $keystone = ($this->create)($this->alpha, 'Rename the registry');
    $atrium = ($this->create)($this->alpha, 'Move the screen');
    $cortex = ($this->create)($this->beta, 'Use the new registry', [$keystone, $atrium]);
    $dependencies = app(TaskDependencies::class);

    expect($cortex->fresh()->statusLabel())->toBe('Blocked')
        ->and(Task::query()->unblocked()->pluck('id')->all())->toBe([$keystone->id, $atrium->id]);

    $keystone->update(['status' => TaskStatus::Done]);

    expect($dependencies->unblockFinished())->toBe(0)
        ->and($cortex->fresh()->isBlocked())->toBeTrue();

    $atrium->update(['status' => TaskStatus::Done]);

    expect($dependencies->unblockFinished())->toBe(1)
        ->and($cortex->fresh()->isBlocked())->toBeFalse()
        ->and($cortex->fresh()->resume_note)->toContain("#{$keystone->id}")->toContain("#{$atrium->id}")->toContain('never composer local:update');
});

it('refuses a cycle, a task waiting on itself and one already done', function () {
    $a = ($this->create)($this->alpha, 'A');
    $b = ($this->create)($this->alpha, 'B', [$a]);
    $c = ($this->create)($this->beta, 'C', [$b]);
    $done = ($this->create)($this->beta, 'Done');
    $done->update(['status' => TaskStatus::Done]);
    $dependencies = app(TaskDependencies::class);

    expect(fn () => $dependencies->add($a, $c))->toThrow(ValidationException::class, 'cycle')
        ->and(fn () => $dependencies->add($a, $a))->toThrow(ValidationException::class)
        ->and(fn () => $dependencies->add($a, $done))->toThrow(ValidationException::class);
});

it('lets you add and remove what a task waits on from its page, and pick one when you queue it', function () {
    $first = ($this->create)($this->alpha, 'First');
    $second = ($this->create)($this->beta, 'Second');

    Livewire::test(ShowTask::class, ['task' => $second])
        ->set('dependsOn', (string) $first->id)
        ->call('addDependency')
        ->assertHasNoErrors()
        ->assertSee('Waiting on another task')
        ->call('removeDependency', $first->id);

    expect($second->fresh()->isBlocked())->toBeFalse();

    Livewire::test(Tasks::class)
        ->set('projectId', (string) $this->beta->id)
        ->set('title', 'Third')
        ->set('dependsOn', (string) $first->id)
        ->call('create')
        ->assertHasNoErrors();

    expect(Task::query()->where('title', 'Third')->sole()->dependencies->modelKeys())->toBe([$first->id]);
});
