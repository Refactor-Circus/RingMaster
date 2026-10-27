<?php

namespace App\Livewire;

use App\Actions\ScanWorkspaces;
use App\Actions\TaskProposals;
use App\Enums\AttentionStatus;
use App\Enums\TaskStatus;
use App\Models\AttentionItem;
use App\Models\Task;
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

    #[On('answered')]
    public function answered(): void {}

    public function render(): View
    {
        return view('livewire.inbox', [
            'open' => $this->items(AttentionStatus::Open)->groupBy('spec_id'),
            'proposals' => Task::query()->with(['project', 'blocking'])->where('status', TaskStatus::Proposed)->oldest()->get(),
            'waiting' => $this->items(AttentionStatus::Answered),
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
}
