<?php

namespace App\Actions;

use App\Enums\CampaignStatus;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Task;
use App\Models\Workspace;
use App\Support\DependencyOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Campaigns: one intent carried out across many packages. Launching queues a
 * task per package, each waiting on the tasks of the packages it requires,
 * so the change lands in dependency order and every task starts from merged
 * work. Cancelling cancels the tasks still open.
 */
final class Campaigns
{
    public function __construct(private readonly DependencyOrder $order, private readonly TaskDependencies $dependencies) {}

    /**
     * @param  list<Project>  $projects
     */
    public function launch(Workspace $workspace, string $title, string $intent, array $projects, TaskPriority $priority = TaskPriority::Normal): Campaign
    {
        $title = Str::limit(trim($title), 200);
        $intent = trim($intent);

        if ($title === '' || $intent === '') {
            throw ValidationException::withMessages(['intent' => 'Give the campaign a title and say what to change.']);
        }

        $projects = array_values(array_filter($projects, fn (Project $project): bool => $project->workspace_id === $workspace->id && ! $project->is_workspace));

        if ($projects === []) {
            throw ValidationException::withMessages(['projects' => 'Pick at least one package of this workspace.']);
        }

        $plan = $this->order->waves($projects);
        $folders = array_map(fn (Project $project): string => $project->folder, $projects);

        return DB::transaction(function () use ($workspace, $title, $intent, $priority, $plan, $folders): Campaign {
            $campaign = Campaign::query()->create(['workspace_id' => $workspace->id, 'title' => $title, 'intent' => $intent, 'status' => CampaignStatus::Running]);
            $tasks = [];

            foreach ($plan['waves'] as $wave) {
                foreach ($wave as $project) {
                    $before = array_map(fn (int $id): string => Project::query()->whereKey($id)->value('folder'), $plan['requires'][$project->id]);
                    $tasks[$project->id] = Task::query()->create([
                        'project_id' => $project->id,
                        'campaign_id' => $campaign->id,
                        'kind' => TaskKind::Idea,
                        'title' => "{$title} ({$project->folder})",
                        'body' => $this->body($campaign, $project, $folders, $before),
                        'priority' => $priority,
                        'status' => TaskStatus::Queued,
                    ]);
                    Activity::record('task.created', "Queued for campaign #{$campaign->id}: {$title}", $tasks[$project->id]);
                }
            }

            foreach ($plan['requires'] as $id => $requires) {
                foreach ($requires as $required) {
                    try {
                        $this->dependencies->add($tasks[$id], $tasks[$required]->load('project', 'spec'), "campaign #{$campaign->id}: {$tasks[$id]->project->folder} requires {$tasks[$required]->project->folder}");
                    } catch (ValidationException) {
                        // A Composer dependency cycle: those packages go side by side.
                    }
                }
            }

            Activity::record('campaign.launched', "Campaign #{$campaign->id} launched: {$title}, ".count($tasks).' package(s) in '.count($plan['waves']).' wave(s)');

            return $campaign;
        });
    }

    public function cancel(Campaign $campaign): Campaign
    {
        DB::transaction(function () use ($campaign): void {
            foreach ($campaign->tasks()->whereIn('status', [...TaskStatus::open(), TaskStatus::Proposed])->get() as $task) {
                $task->update(['status' => TaskStatus::Cancelled]);
                $task->dependencies()->detach();
                Activity::record('task.cancelled', "Cancelled with campaign #{$campaign->id}", $task);
            }

            $campaign->update(['status' => CampaignStatus::Cancelled]);
        });

        return $campaign;
    }

    /**
     * Marks running campaigns whose every task is done as done. Called on each runner tick.
     */
    public function settle(): void
    {
        foreach (Campaign::query()->where('status', CampaignStatus::Running)->with('tasks')->get() as $campaign) {
            if ($campaign->tasks->isNotEmpty() && $campaign->tasks->every(fn (Task $task): bool => $task->status === TaskStatus::Done)) {
                $campaign->update(['status' => CampaignStatus::Done]);
                Activity::record('campaign.done', "Campaign #{$campaign->id} done: {$campaign->title}");
            }
        }
    }

    /**
     * @param  list<string>  $folders
     * @param  list<string>  $before
     */
    private function body(Campaign $campaign, Project $project, array $folders, array $before): string
    {
        return $campaign->intent."\n\n---\n"
            ."This is {$project->folder}'s part of campaign #{$campaign->id}, \"{$campaign->title}\", across ".count($folders).' package(s): '.implode(', ', $folders).".\n"
            ."Change only {$project->folder}. "
            .($before === [] ? 'No package of the campaign comes before it. ' : 'It requires '.implode(', ', $before).', whose part of the campaign is merged before this task starts. ')
            .'Packages that require it follow once this PR is merged, so keep what they use working unless the campaign says otherwise.';
    }
}
