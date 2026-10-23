<?php

namespace App\Livewire;

use App\Actions\AnswerAttentionItem;
use App\Enums\AttentionStatus;
use App\Models\AttentionItem;
use Illuminate\Contracts\View\View;
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
        $answer->handle($this->item(), $this->choice === self::OTHER ? $this->other : $this->choice, $this->note);

        $this->editing = false;
        $this->dispatch('answered');
    }

    public function render(): View
    {
        return view('livewire.answer-item', ['item' => $this->item()]);
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
