<?php

namespace App\Livewire;

use App\Actions\AnswerAttentionItem;
use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Models\AttentionItem;
use App\Support\Precedents;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One question or escalation, with the form to answer it (or change the
 * answer until Claude applies it).
 */
class AnswerItem extends Component
{
    public const string OTHER = '__other__';

    #[Locked]
    public int $itemId;

    #[Locked]
    public bool $showSpec = false;

    public string $choice = '';

    public string $other = '';

    public string $note = '';

    public bool $editing = false;

    /** Approving opens the PR; with tasks unbuilt you confirm that first. */
    public bool $acceptUnbuilt = false;

    public function mount(AttentionItem $item, bool $showSpec = false): void
    {
        $this->itemId = $item->id;
        $this->showSpec = $showSpec;
        $this->editing = $item->status === AttentionStatus::Open;
        $this->fillFrom($item);
    }

    public function edit(): void
    {
        $this->fillFrom($this->item());
        $this->editing = $this->item()->status === AttentionStatus::Answered;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(AnswerAttentionItem $answer): void
    {
        $this->resetErrorBag();
        $item = $this->item();

        if ($item->kind === AttentionKind::Escalation && $this->choice === AttentionKind::APPROVE && $item->spec->unbuiltTasks() > 0 && ! $this->acceptUnbuilt) {
            throw ValidationException::withMessages(['answer' => "Only {$item->spec->tasks_done} of {$item->spec->tasks_total} tasks are built. Tick the box to open the PR anyway, or send it back."]);
        }

        $answer->handle($item, $this->choice === self::OTHER ? $this->other : $this->choice, $this->note);

        $this->editing = false;
        $this->acceptUnbuilt = false;
        $this->dispatch('answered');
    }

    /**
     * Answers the same as the closest precedent, on your click.
     */
    public function usePrecedent(AnswerAttentionItem $answer, Precedents $precedents): void
    {
        $item = $this->item();
        $suggestion = $precedents->suggestion($item);

        if ($suggestion === null) {
            return;
        }

        $decision = $suggestion['decision'];
        $answer->handle($item, $suggestion['option'] ?? $decision->answer, "Same as #{$decision->attention_item_id} in {$decision->project->folder} ({$decision->decided_at->toDateString()})".($decision->note !== null ? ": {$decision->note}" : ''));

        $this->editing = false;
        $this->dispatch('answered');
    }

    public function render(Precedents $precedents): View
    {
        $item = $this->item();

        return view('livewire.answer-item', [
            'item' => $item,
            'precedent' => $this->editing ? $precedents->suggestion($item) : null,
        ]);
    }

    private function item(): AttentionItem
    {
        return AttentionItem::query()->with('spec.project')->findOrFail($this->itemId);
    }

    private function fillFrom(AttentionItem $item): void
    {
        $this->note = (string) $item->note;
        $this->other = '';
        $this->choice = '';

        if ($item->answer !== null) {
            $known = in_array($item->answer, $item->orderedOptions(), true);
            $this->choice = $known ? $item->answer : self::OTHER;
            $this->other = $known ? '' : $item->answer;
        }
    }
}
