<?php

namespace App\Actions;

use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Models\Activity;
use App\Models\AttentionItem;
use App\Support\Precedents;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records your answer. It can change until Claude applies it to the spec
 * folder; the scanner marks it applied once it is gone from there. An
 * answer to a decision is kept as precedent for questions like it.
 */
final class AnswerAttentionItem
{
    public function __construct(private readonly Precedents $precedents, private readonly FixSpecStatus $statuses) {}

    public function handle(AttentionItem $item, string $answer, ?string $note = null): AttentionItem
    {
        $answer = trim($answer);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($item->status, [AttentionStatus::Open, AttentionStatus::Answered], true)) {
            throw ValidationException::withMessages(['answer' => 'This one was already dealt with.']);
        }

        if ($answer === '') {
            throw ValidationException::withMessages(['answer' => 'Choose an option or write your own answer.']);
        }

        if ($item->kind === AttentionKind::Escalation && $answer === AttentionKind::REWORK && $note === null) {
            throw ValidationException::withMessages(['note' => 'Say what to change before sending it back.']);
        }

        $changed = $item->status === AttentionStatus::Answered;
        // Leaving failing checks needs nothing from Claude, so it is done at once.
        $leave = $answer === AttentionKind::LEAVE;
        $item->update([
            'status' => $leave ? AttentionStatus::Closed : AttentionStatus::Answered,
            'answer' => $answer,
            'note' => $note,
            'answered_at' => now(),
            ...($leave ? ['closed_at' => now()] : []),
        ]);

        // An unknown spec status: RingMaster sets the one you chose itself.
        if ($item->source === FixSpecStatus::SOURCE) {
            $this->statuses->apply($item);
        }

        $this->precedents->record($item);
        Activity::record('attention.answered', ($changed ? 'Changed answer: ' : 'Answered: ').Str::limit($item->question, 80).' → '.Str::limit($answer, 80), spec: $item->spec);

        return $item;
    }
}
