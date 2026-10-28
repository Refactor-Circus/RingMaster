<?php

namespace App\Livewire;

use App\Models\Briefing;
use App\Models\Spec;
use App\Support\Briefings;
use App\Support\NightShift;
use App\Support\Reviews;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The morning briefing. With no briefing picked it shows the latest; "Brief
 * me now" makes a fresh one. PRs you tick are reviewed in one go: approved
 * on GitHub, or labelled reviewed where GitHub refuses (your own PRs).
 */
#[Title('Briefing')]
class ShowBriefing extends Component
{
    #[Locked]
    public ?int $briefingId = null;

    /** @var list<int> spec ids to review */
    public array $ticked = [];

    /** @var list<string> */
    public array $reviewed = [];

    public function mount(?Briefing $briefing = null): void
    {
        $this->briefingId = $briefing->id ?? Briefing::query()->latest('id')->value('id');
        $this->tickLowRisk();
    }

    public function briefNow(Briefings $briefings): void
    {
        $this->briefingId = $briefings->generate(notify: false)->id;
        $this->reviewed = [];
        $this->tickLowRisk();
    }

    public function reviewTicked(Reviews $reviews): void
    {
        $lines = [];

        foreach (Spec::query()->with('project')->whereKey($this->ticked)->where('status', 'in-review')->get() as $spec) {
            $lines = [...$lines, ...$reviews->approve($spec)];
        }

        $this->reviewed = $lines === [] ? ['Nothing to review: no ticked PR is in review any more.'] : $lines;
        $this->ticked = [];
    }

    public function render(): View
    {
        $briefing = $this->briefingId === null ? null : Briefing::query()->find($this->briefingId);

        return view('livewire.show-briefing', [
            'briefing' => $briefing,
            'data' => $briefing?->data,
            'night' => ['active' => NightShift::active(), 'spent' => NightShift::spent(), 'budget' => NightShift::budget()],
            'older' => Briefing::query()->latest('id')->limit(14)->get(['id', 'created_at']),
        ]);
    }

    private function tickLowRisk(): void
    {
        $briefing = $this->briefingId === null ? null : Briefing::query()->find($this->briefingId);
        /** @var list<array{id: int, risk: array{level: string}}> $review */
        $review = $briefing?->data['review'] ?? [];
        $this->ticked = array_values(array_map(fn (array $row): int => $row['id'], array_filter($review, fn (array $row): bool => $row['risk']['level'] === 'low')));
    }
}
