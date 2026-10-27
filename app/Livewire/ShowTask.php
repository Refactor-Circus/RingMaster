<?php

namespace App\Livewire;

use App\Actions\ChangeTaskStatus;
use App\Actions\ClaimTasks;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskProposals;
use App\Actions\UpdateTask;
use App\Enums\AttentionStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
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

        return match (true) {
            ! Runner::alive() => 'No runner is running. Start one with `php artisan ring-master:work`.',
            Runner::paused() => 'The runner is paused. Resume it on the Runs page.',
            Runner::waitingToRestart() => 'The runner is restarting to load new code once its running sessions finish.',
            $blocking !== null => "Waiting for run #{$blocking->id} in {$blocking->project->folder} to finish: one session per folder tree.",
            $active->count() >= max(1, (int) config('ring-master.runner.concurrency', 1)) => 'Waiting for a free slot: the runner is at its concurrency limit.',
            default => 'Next in line: the runner picks it up within a few seconds.',
        };
    }

    private function task(): Task
    {
        return Task::query()->with(['project', 'spec', 'blockedBy.project', 'blocking'])->findOrFail($this->taskId);
    }
}
