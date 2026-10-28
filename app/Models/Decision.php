<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An architectural answer you gave, kept as precedent. A new question that
 * reads like it is shown with this answer as a suggestion; it is never
 * applied without you.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $attention_item_id
 * @property string|null $spec_key
 * @property string $question
 * @property list<string> $options
 * @property string $answer
 * @property string|null $note
 * @property string $terms the question's words, normalised, for matching
 * @property CarbonImmutable $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 */
#[Fillable(['project_id', 'attention_item_id', 'spec_key', 'question', 'options', 'answer', 'note', 'terms', 'decided_at'])]
class Decision extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
