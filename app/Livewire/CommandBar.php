<?php

namespace App\Livewire;

use App\Actions\CreateTask;
use App\Enums\TaskKind;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * The prompt that opens with ⌘K: describe what you want and it is queued as
 * an idea for a project, or jump to a spec, a task or a page.
 */
class CommandBar extends Component
{
    public string $query = '';

    public string $projectId = '';

    public function mount(): void
    {
        $this->projectId = (string) ($this->projects()->first()->id ?? '');
    }

    public function queue(CreateTask $create): void
    {
        $this->validate([
            'query' => ['required', 'string', 'min:3', 'max:20000'],
            'projectId' => ['required', Rule::exists('projects', 'id')],
        ], ['query.min' => 'Say a little more about what to build.']);

        $text = trim($this->query);
        $title = Str::limit((string) Str::of($text)->before("\n")->trim(), 120, '…');

        $task = $create->handle(Project::query()->findOrFail((int) $this->projectId), TaskKind::Idea, $title, $title === $text ? null : $text);

        $this->reset('query');
        $this->redirectRoute('tasks.show', $task, navigate: true);
    }

    public function render(): View
    {
        $term = trim($this->query);
        $like = '%'.$term.'%';

        return view('livewire.command-bar', [
            'projects' => $this->projects(),
            'specs' => $term === '' ? collect() : Spec::query()->with('project')->where('title', 'like', $like)->orderByDesc('spec_updated_at')->limit(5)->get(),
            'tasks' => $term === '' ? collect() : Task::query()->with('project')->where('title', 'like', $like)->latest()->limit(5)->get(),
            'pages' => collect([
                ['Board', 'board', 'view-columns'], ['Inbox', 'inbox', 'inbox'], ['Tasks', 'tasks', 'queue-list'],
                ['Runs', 'runs', 'cpu-chip'], ['Workspaces', 'workspaces', 'folder'], ['Settings', 'settings', 'cog-6-tooth'],
            ])->filter(fn (array $page): bool => $term === '' || Str::contains($page[0], $term, ignoreCase: true))->values(),
        ]);
    }

    /**
     * @return Collection<int, Project>
     */
    private function projects(): Collection
    {
        return Project::query()
            ->whereHas('workspace', fn (Builder $query) => $query->where('enabled', true))
            ->orderByDesc('is_workspace')
            ->orderBy('folder')
            ->get();
    }
}
