<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Run;
use App\Models\Spec;
use App\Models\Task;
use App\Support\WorkflowMap;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Workflow')]
class Workflow extends Component
{
    #[Url]
    public string $project = '';

    public function render(): View
    {
        $projects = Project::query()->whereHas('workspace', fn (Builder $query) => $query->where('enabled', true))->orderByDesc('is_workspace')->orderBy('folder')->get();
        $current = $projects->firstWhere('id', (int) $this->project) ?? $projects->first();
        $map = null;
        $error = null;

        if ($current !== null) {
            try {
                $map = WorkflowMap::load($current->path);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('livewire.workflow', [
            'projects' => $projects,
            'current' => $current,
            'map' => $map,
            'error' => $error,
            'counts' => $current === null ? [] : $this->counts($current),
            'metrics' => $current === null ? null : $this->metrics($current),
        ]);
    }

    /**
     * How the runner did over the last week, for this project or, for a
     * workspace root, every project in its workspace.
     *
     * @return array{runs: int, running: int, succeeded: int, cost: float, jobs: int, median: ?int, ci_fixes: int, refused: int, failures: array<string, int>}
     */
    private function metrics(Project $project): array
    {
        $projects = $project->is_workspace ? Project::query()->where('workspace_id', $project->workspace_id)->pluck('id')->all() : [$project->id];
        $runs = Run::query()->whereIn('project_id', $projects)->where('started_at', '>=', now()->subWeek())->get();
        $durations = $runs->filter(fn (Run $run): bool => $run->finished_at !== null)->map(fn (Run $run): int => (int) $run->started_at?->diffInSeconds($run->finished_at))->sort()->values();

        return [
            'runs' => $runs->count(),
            'running' => $runs->filter(fn (Run $run): bool => $run->status->isActive())->count(),
            'succeeded' => $runs->where('status', RunStatus::Succeeded)->count(),
            'cost' => (float) $runs->sum(fn (Run $run): float => (float) $run->cost_usd),
            'jobs' => $runs->pluck('task_id')->filter()->unique()->count(),
            'median' => $durations->isEmpty() ? null : (int) $durations[intdiv($durations->count(), 2)],
            'ci_fixes' => Activity::query()->where('type', 'ci.autofix')->where('created_at', '>=', now()->subWeek())->whereIn('spec_id', Spec::query()->whereIn('project_id', $projects)->select('id'))->count(),
            'refused' => $runs->sum(fn (Run $run): int => count($run->refused ?? [])),
            'failures' => $runs->where('status', RunStatus::Failed)->groupBy(fn (Run $run): string => $run->failure?->label() ?? 'unknown')->map->count()->sortDesc()->all(),
        ];
    }

    /**
     * Jobs per status, with `approved` split by whether tasks.md exists yet,
     * and the queue as `queued` (tasks with no spec yet, so at the ticket).
     *
     * @return array<string, int>
     */
    private function counts(Project $project): array
    {
        $counts = [];

        foreach (Spec::query()->where('project_id', $project->id)->get(['status', 'tasks_total']) as $spec) {
            $status = $spec->status === 'approved' ? ($spec->tasks_total > 0 ? 'approved:tasked' : 'approved:untasked') : $spec->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $counts['queued'] = Task::query()->where('project_id', $project->id)->whereNull('spec_id')->whereIn('status', TaskStatus::open())->count();

        return $counts;
    }
}
