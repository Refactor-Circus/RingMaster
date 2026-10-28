<?php

namespace App\Livewire;

use App\Actions\Campaigns as CampaignActions;
use App\Enums\TaskPriority;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\DependencyOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Campaigns, and the form for a new one: say what to change, pick the
 * packages, see the order the change lands in, then launch it.
 */
#[Title('Campaigns')]
class Campaigns extends Component
{
    public string $workspaceId = '';

    public string $title = '';

    public string $intent = '';

    /** @var list<string> project ids */
    public array $projectIds = [];

    public int $priority = 1;

    public function mount(): void
    {
        $this->workspaceId = (string) (Workspace::query()->where('enabled', true)->orderBy('name')->value('id') ?? '');
        $this->selectAll();
    }

    public function updatedWorkspaceId(): void
    {
        $this->selectAll();
    }

    public function selectAll(): void
    {
        $ids = $this->packages()->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $this->projectIds = array_values(array_map('strval', $ids));
    }

    public function launch(CampaignActions $campaigns): void
    {
        $this->validate([
            'workspaceId' => ['required', Rule::exists('workspaces', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'intent' => ['required', 'string', 'max:20000'],
            'projectIds' => ['required', 'array', 'min:1'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ], attributes: ['projectIds' => 'packages']);

        $campaign = $campaigns->launch(
            Workspace::query()->findOrFail((int) $this->workspaceId),
            $this->title,
            $this->intent,
            array_values(Project::query()->whereKey(array_map('intval', $this->projectIds))->get()->all()),
            TaskPriority::from($this->priority),
        );

        $this->redirectRoute('campaigns.show', $campaign, navigate: true);
    }

    public function render(DependencyOrder $order): View
    {
        $chosen = $this->packages()->whereIn('id', array_map('intval', $this->projectIds));

        return view('livewire.campaigns', [
            'campaigns' => Campaign::query()->withCount(['tasks', 'tasks as done_count' => fn ($query) => $query->where('status', 'done')])->latest('id')->get(),
            'workspaces' => Workspace::query()->where('enabled', true)->orderBy('name')->get(),
            'packages' => $this->packages(),
            'plan' => $chosen->isEmpty() ? null : $order->waves($chosen),
        ]);
    }

    /**
     * @return Collection<int, Project>
     */
    private function packages(): Collection
    {
        return Project::query()->where('workspace_id', (int) $this->workspaceId)->where('is_workspace', false)->orderBy('folder')->get();
    }
}
