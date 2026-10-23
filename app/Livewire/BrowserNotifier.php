<?php

namespace App\Livewire;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lives in the layout and tells an open tab when something new needs you,
 * so the browser can show a notification (once you enable them in Settings).
 */
class BrowserNotifier extends Component
{
    public const array TYPES = ['attention.raised', 'task.failed'];

    #[Locked]
    public int $seen = 0;

    public function mount(): void
    {
        $this->seen = (int) Activity::query()->whereIn('type', self::TYPES)->max('id');
    }

    public function check(): void
    {
        $new = Activity::query()->whereIn('type', self::TYPES)->where('id', '>', $this->seen)->orderBy('id')->get();

        if ($new->isEmpty()) {
            return;
        }

        $this->seen = (int) $new->max('id');
        $failed = $new->where('type', 'task.failed')->isNotEmpty();

        $this->dispatch('ring-master-notify',
            title: $failed ? 'RingMaster: a task failed' : ($new->count() === 1 ? 'RingMaster: 1 thing needs you' : "RingMaster: {$new->count()} things need you"),
            body: Str::limit($new->pluck('message')->implode("\n"), 300),
            url: $failed ? route('tasks', ['show' => 'done']) : route('inbox'),
        );
    }

    public function render(): View
    {
        return view('livewire.browser-notifier');
    }
}
