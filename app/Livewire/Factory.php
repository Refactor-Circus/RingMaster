<?php

namespace App\Livewire;

use App\Actions\Improvements;
use App\Enums\ImprovementStatus;
use App\Enums\RunKind;
use App\Enums\RunStatus;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Run;
use App\Runner\Runner;
use App\Support\Improver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The factory improving itself: the analyst's proposals, each with its
 * evidence, the task that builds it, and the replays that test it against
 * recent jobs before you adopt it.
 */
#[Title('Factory')]
class Factory extends Component
{
    public function analyse(): void
    {
        Improver::request();
    }

    public function accept(int $id, Improvements $improvements): void
    {
        $improvements->accept(Improvement::query()->findOrFail($id));
    }

    public function evaluate(int $id, Improvements $improvements): void
    {
        $improvements->evaluate(Improvement::query()->findOrFail($id));
    }

    public function adopt(int $id, Improvements $improvements): void
    {
        $improvements->adopt(Improvement::query()->findOrFail($id));
    }

    public function dismiss(int $id, Improvements $improvements): void
    {
        $improvements->dismiss(Improvement::query()->findOrFail($id));
    }

    public function render(): View
    {
        $analysing = Run::query()->where('kind', RunKind::Improve)->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->exists();

        return view('livewire.factory', [
            'improvements' => Improvement::query()->with('task.spec')->latest('id')->limit(50)->get(),
            'analysing' => $analysing,
            'waiting' => ! $analysing && Improver::requested() ? $this->waiting() : null,
            'replaying' => Run::query()->where('kind', RunKind::Replay)->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get()->keyBy('improvement_id'),
            'open' => [ImprovementStatus::Open, ImprovementStatus::Building, ImprovementStatus::Evaluating, ImprovementStatus::Evaluated],
        ]);
    }

    /**
     * Why an analyst run you asked for hasn't started yet.
     */
    private function waiting(): string
    {
        $roots = Project::query()->where('is_workspace', true)->pluck('path')->all();
        $blocking = Run::query()->with('project')->whereIn('status', [RunStatus::Running, RunStatus::Stopping])->get()
            ->first(fn (Run $run): bool => array_any($roots, fn (string $root): bool => Runner::overlaps($root, [$run->project->path])));

        return Runner::holdReason()
            ?? ($blocking !== null ? "Waiting for run #{$blocking->id} in {$blocking->project->folder} to finish: the analyst reads the whole workspace, one session per folder tree." : null)
            ?? 'Requested: the runner starts the analyst within a few seconds.';
    }
}
