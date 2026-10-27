<?php

namespace App\Livewire;

use App\Enums\TaskStatus;
use App\Models\Project;
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
        ]);
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
