<?php

namespace App\Livewire;

use App\Support\NavCounts;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Lives in the layout and keeps the navigation's badges current: the layout
 * itself only renders when you change page.
 */
class NavCounter extends Component
{
    public function check(): void
    {
        $this->dispatch('ring-master-counts', ...NavCounts::get());
    }

    public function render(): View
    {
        return view('livewire.nav-counter');
    }
}
