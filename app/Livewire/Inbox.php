<?php

namespace App\Livewire;

use App\Actions\Chores;
use App\Actions\ScanWorkspaces;
use App\Actions\TaskProposals;
use App\Enums\AttentionStatus;
use App\Enums\ChoreStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Chore;
use App\Models\Run;
use App\Models\Task;
use App\Runner\Runner;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Inbox')]
class Inbox extends Component
{
    public function mount(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    public function refresh(ScanWorkspaces $scan): void
    {
        $scan->ifStale();
    }

    public function acceptProposal(int $id, TaskProposals $proposals): void
    {
        $proposals->accept(Task::query()->findOrFail($id));
    }

    public function declineProposal(int $id, TaskProposals $proposals): void
    {
        $proposals->dismiss(Task::query()->findOrFail($id));
    }

    public function approveChore(int $id, Chores $chores): void
    {
        $chores->approve(Chore::query()->findOrFail($id));
    }

    public function dismissChore(int $id, Chores $chores): void
    {
        $chores->dismiss(Chore::query()->findOrFail($id));
    }

    public function retryChore(int $id, Chores $chores): void
    {
        $chores->retry(Chore::query()->findOrFail($id));
    }

    #[On('answered')]
    public function answered(): void {}

    public function render(): View
    {
        return view('livewire.inbox', [
            'open' => $this->items(AttentionStatus::Open)->groupBy('spec_id'),
            'proposals' => Task::query()->with(['project', 'dependents', 'dependencies.project'])->where('status', TaskStatus::Proposed)->oldest()->get(),
            'chores' => Chore::query()->with('project')->where('status', ChoreStatus::Open)->oldest()->get(),
            'choresDone' => Chore::query()->with('project')->whereIn('status', [ChoreStatus::Done, ChoreStatus::Failed])->latest('resolved_at')->limit(5)->get(),
            'waiting' => $waiting = $this->items(AttentionStatus::Answered),
            'applying' => $this->applying($waiting),
            'resolved' => $this->items(AttentionStatus::Applied, AttentionStatus::Closed)->take(10),
        ]);
    }

    /**
     * @return Collection<int, AttentionItem>
     */
    private function items(AttentionStatus ...$statuses): Collection
    {
        return AttentionItem::query()
            ->with('spec.project')
            ->whereIn('status', $statuses)
            ->whereHas('spec.project.workspace', fn (Builder $query) => $query->where('enabled', true))
            ->latest('updated_at')
            ->latest('id')
            ->get();
    }

    /**
     * Per project with answers waiting: applying now, or why not yet.
     *
     * @param  Collection<int, AttentionItem>  $waiting
     * @return array<string, string>
     */
    private function applying(Collection $waiting): array
    {
        $active = Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get();
        $hold = Runner::holdReason();
        $status = [];

        foreach ($waiting->map(fn (AttentionItem $item) => $item->spec->project)->unique('id') as $project) {
            $applying = $active->first(fn (Run $run): bool => $run->project_id === $project->id && $run->kind === RunKind::Answers);
            $blocking = $active->first(fn (Run $run): bool => Runner::overlaps($project->path, [$run->project->path]));

            $status[$project->folder] = match (true) {
                $applying !== null => "applying now (run #{$applying->id})",
                $hold !== null => $hold,
                $blocking !== null => "waits for run #{$blocking->id} to finish: one session per folder tree",
                default => 'starts within a few seconds',
            };
        }

        return $status;
    }
}
