<?php

namespace App\Models;

use App\Enums\AttentionKind;
use App\Enums\AttentionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a spec is waiting on you for: an architectural question, or an
 * escalated job to accept or send back. Imported from the spec folder;
 * your answer stays here until Claude applies it.
 *
 * @property int $id
 * @property int $spec_id
 * @property string $key
 * @property AttentionKind $kind
 * @property string $question
 * @property list<string> $options
 * @property string|null $recommendation
 * @property string|null $evidence
 * @property string|null $source
 * @property AttentionStatus $status
 * @property string|null $answer
 * @property string|null $note
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Spec $spec
 */
#[Fillable(['spec_id', 'key', 'kind', 'question', 'options', 'recommendation', 'evidence', 'source', 'status', 'answer', 'note', 'answered_at', 'applied_at', 'closed_at'])]
class AttentionItem extends Model
{
    /**
     * @return BelongsTo<Spec, $this>
     */
    public function spec(): BelongsTo
    {
        return $this->belongsTo(Spec::class);
    }

    /**
     * The option a recommendation names. Agents sometimes recommend by label
     * ("A", "Option 2") or with only the start of the option's text; those
     * resolve to the full option. Anything else stays as written and is
     * offered as an option of its own.
     *
     * @param  list<string>  $options
     */
    public static function resolveRecommendation(?string $recommendation, array $options): ?string
    {
        $wanted = trim((string) $recommendation);

        if ($wanted === '' || in_array($wanted, $options, true)) {
            return $recommendation;
        }

        $label = static fn (string $text): ?string => preg_match('/^\(?(?:option\s+)?([a-z]|\d{1,2})(?:[.):]|\s*$|\s+-)/i', trim($text), $m) ? strtolower($m[1]) : null;

        foreach ($options as $option) {
            if (strcasecmp(trim($option), $wanted) === 0) {
                return $option;
            }
        }

        if (($named = $label($wanted)) !== null) {
            foreach ($options as $option) {
                if ($label($option) === $named) {
                    return $option;
                }
            }

            // A bare letter or number with unlabelled options: its position.
            $index = ctype_digit($named) ? (int) $named - 1 : ord($named) - ord('a');

            if (preg_match('/^\(?(?:option\s+)?([a-z]|\d{1,2})[.)]?$/i', $wanted) && isset($options[$index])) {
                return $options[$index];
            }
        }

        $matches = array_values(array_filter($options, static fn (string $option): bool => mb_strlen($wanted) >= 12 && str_starts_with(mb_strtolower($option), mb_strtolower($wanted))));

        return count($matches) === 1 ? $matches[0] : $recommendation;
    }

    /**
     * Options with the recommendation first, as /ring-master-decide asks them.
     *
     * @return list<string>
     */
    public function orderedOptions(): array
    {
        $options = $this->options;

        if ($this->recommendation !== null && ! in_array($this->recommendation, $options, true) && $this->kind === AttentionKind::Decision) {
            $options[] = $this->recommendation;
        }

        usort($options, fn (string $a, string $b): int => ($b === $this->recommendation) <=> ($a === $this->recommendation));

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttentionKind::class,
            'status' => AttentionStatus::class,
            'options' => 'array',
            'answered_at' => 'datetime',
            'applied_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
