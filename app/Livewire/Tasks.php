<?php

namespace App\Livewire;

use App\Actions\CreateTask;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Tasks')]
class Tasks extends Component
{
    #[Url]
    public string $show = 'open';

    public string $projectId = '';

    public string $kind = 'idea';

    public string $title = '';

    public string $body = '';

    public string $ticketRef = '';

    public string $specId = '';

    public int $priority = 1;

    public string $dependsOn = '';

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'projectId' => ['required', Rule::exists('projects', 'id')],
            'kind' => ['required', Rule::enum(TaskKind::class)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'ticketRef' => ['nullable', 'string', 'max:100', Rule::requiredIf($this->kind === TaskKind::Ticket->value)],
            'specId' => ['nullable', Rule::requiredIf($this->kind === TaskKind::Spec->value), Rule::exists('specs', 'id')->where('project_id', (int) $this->projectId)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'dependsOn' => ['nullable', Rule::exists('tasks', 'id')],
        ];
    }

    public function updatedSpecId(): void
    {
        $spec = $this->specId === '' ? null : Spec::query()->find((int) $this->specId);

        if ($spec !== null && $this->title === '') {
            $this->title = $spec->title;
        }
    }

    public function create(CreateTask $create): void
    {
        $this->validate();

        $kind = TaskKind::from($this->kind);
        $task = $create->handle(
            Project::query()->findOrFail((int) $this->projectId),
            $kind,
            $this->title,
            $this->body === '' ? null : $this->body,
            $this->ticketRef === '' ? null : $this->ticketRef,
            $kind === TaskKind::Spec ? Spec::query()->findOrFail((int) $this->specId) : null,
            TaskPriority::from($this->priority),
            $this->dependsOn === '' ? [] : [Task::query()->with('project', 'spec')->findOrFail((int) $this->dependsOn)],
        );

        $this->reset('kind', 'title', 'body', 'ticketRef', 'specId', 'priority', 'dependsOn');
        Flux::modal('new-task')->close();
        $this->redirectRoute('tasks.show', $task, navigate: true);
    }

    public function render(): View
    {
        $statuses = match ($this->show) {
            'done' => array_values(array_filter(TaskStatus::cases(), fn (TaskStatus $status): bool => ! $status->isOpen())),
            'all' => TaskStatus::cases(),
            default => TaskStatus::open(),
        };

        return view('livewire.tasks', [
            'tasks' => Task::query()->with(['project', 'spec'])->whereIn('status', $statuses)->inQueueOrder()->get(),
            'projects' => Project::query()->whereHas('workspace', fn (Builder $query) => $query->where('enabled', true))->orderByDesc('is_workspace')->orderBy('folder')->get(),
            // Open tasks in the chosen project's workspace the new one can wait on.
            'waitable' => $this->projectId === '' ? collect() : Task::query()
                ->with('project')
                ->whereIn('status', [...TaskStatus::open(), TaskStatus::Proposed])
                ->whereHas('project', fn (Builder $query) => $query->where('workspace_id', Project::query()->whereKey((int) $this->projectId)->value('workspace_id')))
                ->latest('id')
                ->limit(100)
                ->get(),
            'specs' => $this->projectId === '' ? collect() : Spec::query()->where('project_id', (int) $this->projectId)->whereDoesntHave('tasks', fn (Builder $query) => $query->whereIn('status', TaskStatus::open()))->orderByDesc('spec_updated_at')->get(),
        ]);
    }
}
