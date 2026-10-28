<?php

namespace App\Livewire;

use App\Actions\Campaigns;
use App\Enums\TaskStatus;
use App\Models\Campaign;
use App\Models\Task;
use App\Support\DependencyOrder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowCampaign extends Component
{
    #[Locked]
    public int $campaignId;

    public function mount(Campaign $campaign): void
    {
        $this->campaignId = $campaign->id;
    }

    public function cancelCampaign(Campaigns $campaigns): void
    {
        $campaigns->cancel(Campaign::query()->findOrFail($this->campaignId));
    }

    public function render(DependencyOrder $order): View
    {
        $campaign = Campaign::query()->with(['tasks.project', 'tasks.spec', 'tasks.dependencies'])->findOrFail($this->campaignId);
        $byProject = $campaign->tasks->keyBy('project_id');
        $waves = array_map(
            fn (array $wave): array => array_values(array_filter(array_map(fn ($project): ?Task => $byProject->get($project->id), $wave))),
            $order->waves($campaign->tasks->pluck('project')->all())['waves'],
        );

        return view('livewire.show-campaign', [
            'campaign' => $campaign,
            'waves' => $waves,
            'done' => $campaign->tasks->where('status', TaskStatus::Done)->count(),
        ])->title($campaign->title);
    }
}
