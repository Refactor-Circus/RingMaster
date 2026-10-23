<?php

namespace App\Livewire;

use App\Actions\ScanWorkspaces;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Spec;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RefactorCircus\Ringside\Specs\Board as RingsideBoard;

#[Title('Board')]
class Board extends Component
{
    #[Url]
    public string $project = '';

    #[Url]
    public string $search = '';

    public function mount(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    public function refresh(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    public function render(): View
    {
        $specs = Spec::query()
            ->with('project')
            ->whereHas('project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->when($this->project !== '', fn (Builder $query) => $query->where('project_id', (int) $this->project))
            ->when($this->search !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$this->search.'%'))
            ->orderByDesc('spec_updated_at')
            ->get()
            ->groupBy('column');

        $queue = Task::query()
            ->with('project')
            ->whereNull('spec_id')
            ->whereIn('status', TaskStatus::open())
            ->whereHas('project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->when($this->project !== '', fn (Builder $query) => $query->where('project_id', (int) $this->project))
            ->when($this->search !== '', fn (Builder $query) => $query->where('title', 'like', '%'.$this->search.'%'))
            ->inQueueOrder()
            ->get();

        return view('livewire.board', [
            'queue' => $queue,
            'columns' => array_keys(RingsideBoard::COLUMNS),
            'specs' => $specs,
            'projects' => Project::query()->whereHas('workspace', fn (Builder $query) => $query->where('enabled', true))->orderBy('folder')->get(),
        ]);
    }
}
