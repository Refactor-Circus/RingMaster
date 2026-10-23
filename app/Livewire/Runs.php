<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Runner\Runner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Runs')]
class Runs extends Component
{
    public function togglePause(): void
    {
        Runner::pause(! Runner::paused());
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
            'concurrency' => (int) config('ring-master.runner.concurrency', 1),
            'runs' => Run::query()->with(['project', 'task'])->latest('id')->limit(50)->get(),
            'spent' => (float) Run::query()->where('started_at', '>=', now()->subDay())->sum('cost_usd'),
        ]);
    }
}
