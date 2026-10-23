<?php

namespace App\Livewire;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The live feed: what agents and you did, newest first. New lines slide in.
 */
class ActivityFeed extends Component
{
    public int $limit = 40;

    public function render(): View
    {
        return view('livewire.activity-feed', [
            'entries' => Activity::query()->with('task')->latest()->latest('id')->limit($this->limit)->get(),
        ]);
    }

    public static function tone(string $type): string
    {
        return match (true) {
            $type === 'attention.raised' => 'wait',
            $type === 'task.failed' => 'fail',
            $type === 'attention.applied', $type === 'run.finished' => 'done',
            str_starts_with($type, 'run.'), $type === 'task.claimed', $type === 'task.progress' => 'live',
            str_starts_with($type, 'spec.') => 'review',
            default => 'idle',
        };
    }
}
