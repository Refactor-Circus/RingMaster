<?php

namespace App\Livewire;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Runner\RunLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShowRun extends Component
{
    #[Locked]
    public int $runId;

    public function mount(Run $run): void
    {
        $this->runId = $run->id;
    }

    public function stopRun(): void
    {
        Run::query()->whereKey($this->runId)->where('status', RunStatus::Running)->update(['status' => RunStatus::Stopping]);
    }

    public function render(RunLog $log): View
    {
        $run = Run::query()->with(['project', 'task'])->findOrFail($this->runId);
        $errors = is_file($run->log_path.'.err') ? trim((string) file_get_contents($run->log_path.'.err')) : '';

        return view('livewire.show-run', [
            'run' => $run,
            'entries' => $log->entries($run->log_path),
            'stderr' => mb_substr($errors, -4000),
        ])->title("Run #{$run->id}");
    }
}
