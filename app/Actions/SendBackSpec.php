<?php

namespace App\Actions;

use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use App\Models\AttentionItem;
use App\Models\Spec;
use Illuminate\Validation\ValidationException;

/**
 * Sends a job in review back to the factory with your note. It becomes an
 * answered escalation, so the runner applies it with /ring-master-rework;
 * the scanner marks it applied once the job leaves review.
 */
final class SendBackSpec
{
    public const string KEY = 'rework:';

    public function __construct(private readonly AnswerAttentionItem $answer, private readonly ChangeTaskStatus $tasks) {}

    public function handle(Spec $spec, string $note): AttentionItem
    {
        if ($spec->status !== 'in-review') {
            throw ValidationException::withMessages(['note' => 'Only a job in review can be sent back here.']);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say what to change before sending it back.']);
        }

        $item = $spec->attentionItems()->where('key', 'like', self::KEY.'%')->where('status', AttentionStatus::Answered)->first()
            ?? $spec->attentionItems()->create([
                'key' => self::KEY.now()->getTimestamp(),
                'kind' => AttentionKind::Escalation,
                'question' => 'You sent this job back from review. Rework it with your note?',
                'options' => [AttentionKind::REWORK],
                'source' => 'review',
                'status' => AttentionStatus::Open,
            ]);

        // Sending it back means build it: a paused task would stop the rework from resuming.
        foreach ($spec->tasks()->whereNotNull('paused_at')->get() as $task) {
            $this->tasks->resume($task);
        }

        return $this->answer->handle($item, AttentionKind::REWORK, $note);
    }
}
