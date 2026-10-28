<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Runner\RunLog;
use App\Runner\Runner;
use App\Support\Capacity;
use App\Support\NightShift;
use App\Support\Planner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Runs')]
class Runs extends Component
{
    public function togglePause(): void
    {
        Runner::pause(! Runner::paused());
    }

    public function plan(): void
    {
        Planner::request();
    }

    public function concurrency(int $by): void
    {
        Runner::setConcurrency(Runner::concurrency() + $by);
    }

    public function stopRun(int $id): void
    {
        Run::query()->whereKey($id)->where('status', RunStatus::Running)->update(['status' => RunStatus::Stopping]);
    }

    public function render(): View
    {
        return view('livewire.runs', [
            'alive' => Runner::alive(),
            'paused' => Runner::paused(),
            'concurrency' => Runner::concurrency(),
            'backoff' => Runner::backoffUntil(),
            'overloaded' => Runner::overloaded(),
            'capacity' => app(Capacity::class)->explain(),
            'night' => NightShift::active() ? ['spent' => NightShift::spent(), 'budget' => NightShift::budget(), 'exhausted' => NightShift::exhausted()] : null,
            'refused' => $this->refused(),
            'runs' => Run::query()->with(['project', 'task'])->latest('id')->limit(50)->get(),
            'spent' => (float) Run::query()->where('started_at', '>=', now()->subDay())->sum('cost_usd'),
        ]);
    }

    /**
     * Refused calls of the last week, grouped by the allowlist entry that would
     * let them through (or why none should), most frequent first.
     *
     * @return Collection<string, array{count: int, example: string}>
     */
    private function refused(): Collection
    {
        return Run::query()->whereNotNull('refused')->where('started_at', '>=', now()->subWeek())->pluck('refused')
            ->flatten()
            ->filter(fn (mixed $call): bool => is_string($call))
            ->groupBy(fn (string $call): string => RunLog::suggestion($call))
            ->map(fn (Collection $calls): array => ['count' => $calls->count(), 'example' => (string) $calls->last()])
            ->sortByDesc('count');
    }
}
