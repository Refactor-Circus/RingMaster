<?php

namespace App\Livewire;

use App\Actions\ChangeTaskStatus;
use App\Actions\ScanWorkspaces;
use App\Actions\UpdateTask;
use App\Enums\AttentionStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\Task;
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

    public function render(): View
    {
        $task = $this->task();

        return view('livewire.show-task', [
            'task' => $task,
            'items' => $task->spec?->attentionItems()->whereIn('status', [AttentionStatus::Open, AttentionStatus::Answered])->get() ?? collect(),
            'timeline' => $task->timeline()->limit(100)->get(),
            'runs' => $task->runs()->latest('id')->get(),
        ])->title($task->title);
    }

    private function task(): Task
    {
        return Task::query()->with(['project', 'spec'])->findOrFail($this->taskId);
    }
}
