<?php

namespace App\Livewire;

use App\Actions\ChangeTaskStatus;
use App\Actions\ClaimTasks;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskDependencies;
use App\Actions\TaskProposals;
use App\Actions\UpdateTask;
use App\Enums\AttentionStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Run;
use App\Models\Task;
use App\Runner\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ShowTask extends Component
{
    #[Locked]
    public int $taskId;

    public bool $editing = false;

    public string $title = '';

    public string $body = '';

    public string $ticketRef = '';

    public int $priority = 1;

    public string $dependsOn = '';

    public function mount(Task $task): void
    {
        $this->taskId = $task->id;
    }

    public function refresh(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    #[On('answered')]
    public function answered(): void {}

    public function edit(): void
    {
        $task = $this->task();
        $this->fill(['title' => $task->title, 'body' => (string) $task->body, 'ticketRef' => (string) $task->ticket_ref, 'priority' => $task->priority->value, 'editing' => true]);
    }

    public function save(UpdateTask $update): void
    {
        $task = $this->task();

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'ticketRef' => ['nullable', 'string', 'max:100', Rule::requiredIf($task->kind === TaskKind::Ticket)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        $update->handle($task, $this->title, $this->body === '' ? null : $this->body, $this->ticketRef === '' ? null : $this->ticketRef, TaskPriority::from($this->priority));
        $this->editing = false;
    }

    public function cancelTask(ChangeTaskStatus $change): void
    {
        $change->cancel($this->task());
    }

    public function requeue(ChangeTaskStatus $change): void
    {
        $change->requeue($this->task());
    }

    public function pause(ChangeTaskStatus $change): void
    {
        $change->pause($this->task());
    }

    public function resume(ChangeTaskStatus $change): void
    {
        $change->resume($this->task());
    }

    public function acceptProposal(TaskProposals $proposals): void
    {
        $proposals->accept($this->task());
    }

    public function declineProposal(TaskProposals $proposals): void
    {
        $proposals->dismiss($this->task());
    }

    public function addDependency(TaskDependencies $dependencies): void
    {
        $this->validate(['dependsOn' => ['required', 'integer', 'exists:tasks,id']], attributes: ['dependsOn' => 'task']);

        $dependencies->add($this->task(), Task::query()->with('project', 'spec')->findOrFail((int) $this->dependsOn));
        $this->dependsOn = '';
    }

    public function removeDependency(int $id, TaskDependencies $dependencies): void
    {
        $dependencies->remove($this->task(), Task::query()->findOrFail($id));
    }

    public function restart(ChangeTaskStatus $change): void
    {
        $change->restart($this->task());
    }

    public function render(): View
    {
        $task = $this->task();

        return view('livewire.show-task', [
            'task' => $task,
            'items' => $task->spec?->attentionItems()->whereIn('status', [AttentionStatus::Open, AttentionStatus::Answered])->get() ?? collect(),
            'timeline' => $task->timeline()->limit(100)->get(),
            // An answers run applies your answers in the task's folder and carries the job on from there.
            'runs' => Run::query()
                ->where(fn ($query) => $query->where('task_id', $task->id)
                    ->orWhere(fn ($query) => $query->whereNull('task_id')->where('kind', RunKind::Answers)->where('project_id', $task->project_id)->where('started_at', '>=', $task->created_at)))
                ->latest('id')
                ->get(),
            'waiting' => $this->waiting($task),
            // Open tasks in the same workspace it could wait on.
            'candidates' => $task->status->isOpen() ? Task::query()
                ->with('project')
                ->whereKeyNot($task->id)
                ->whereNotIn('id', $task->dependencies->modelKeys())
                ->whereIn('status', array_filter(TaskStatus::cases(), fn (TaskStatus $status): bool => $status->isOpen() || $status === TaskStatus::Proposed))
                ->whereHas('project', fn ($query) => $query->where('workspace_id', $task->project->workspace_id))
                ->latest('id')
                ->limit(100)
                ->get() : collect(),
        ])->title($task->title);
    }

    /**
     * Why a task that is ready to be picked up hasn't been yet, so a resume
     * or a restart never looks like nothing happened.
     */
    private function waiting(Task $task): ?string
    {
        if ($task->claimed_by !== null || Run::query()->where('project_id', $task->project_id)->where(fn ($query) => $query->where('task_id', $task->id)->orWhere('kind', RunKind::Answers))->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->exists() || ! app(ClaimTasks::class)->claimable($task->id)) {
            return null;
        }

        $active = Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get();
        $blocking = $active->first(fn (Run $run): bool => Runner::overlaps($task->project->path, [$run->project->path]));

        return Runner::holdReason()
            ?? ($blocking !== null ? "Waiting for run #{$blocking->id} in {$blocking->project->folder} to finish: one session per folder tree." : null)
            ?? 'Next in line: the runner picks it up within a few seconds.';
    }

    private function task(): Task
    {
        return Task::query()->with(['project', 'spec', 'dependencies.project', 'dependents.project'])->findOrFail($this->taskId);
    }
}
