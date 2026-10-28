<?php

namespace App\Support;

use App\Enums\AttentionKind;
use App\Models\AttentionItem;
use App\Models\Decision;
use Illuminate\Support\Str;

/**
 * Your past architectural answers, as precedent for new questions. A new
 * question is matched on its words (stop words dropped, simple stems), and
 * the closest past answer is offered as a suggestion: one click answers the
 * same, and nothing is ever applied without that click.
 */
final class Precedents
{
    private const array STOP = [
        'the', 'and', 'for', 'with', 'should', 'would', 'could', 'this', 'that', 'these', 'those', 'what', 'which', 'when', 'where',
        'how', 'why', 'into', 'from', 'over', 'under', 'use', 'using', 'are', 'was', 'were', 'will', 'can', 'not', 'but', 'its',
        'has', 'have', 'had', 'does', 'did', 'than', 'then', 'each', 'any', 'all', 'one', 'our', 'your', 'their', 'there', 'here',
        'they', 'them', 'about', 'via', 'per', 'only', 'also', 'more', 'most', 'just', 'like', 'both', 'either', 'whether', 'keep',
    ];

    /**
     * Keeps your answer to a decision as precedent; a changed answer replaces it.
     */
    public function record(AttentionItem $item): void
    {
        if ($item->kind !== AttentionKind::Decision || $item->answer === null) {
            return;
        }

        $item->loadMissing('spec');

        Decision::query()->updateOrCreate(['attention_item_id' => $item->id], [
            'project_id' => $item->spec->project_id,
            'spec_key' => $item->spec->key,
            'question' => $item->question,
            'options' => $item->options,
            'answer' => $item->answer,
            'note' => $item->note,
            'terms' => implode(' ', self::terms($item->question)),
            'decided_at' => $item->answered_at ?? now(),
        ]);
    }

    /**
     * Past decisions that read like $question, closest first.
     *
     * @return list<array{decision: Decision, similarity: float}>
     */
    public function similar(string $question, ?int $exceptItem = null, int $limit = 3, ?float $min = null): array
    {
        $min ??= (float) config('ring-master.precedent.min_similarity', 0.5);
        $terms = self::terms($question);

        if ($terms === []) {
            return [];
        }

        $matches = [];

        foreach (Decision::query()->with('project')->when($exceptItem !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('attention_item_id')->orWhere('attention_item_id', '!=', $exceptItem)))->latest('decided_at')->limit(2000)->get() as $decision) {
            $similarity = self::similarity($terms, explode(' ', $decision->terms));

            if ($similarity >= $min) {
                $matches[] = ['decision' => $decision, 'similarity' => round($similarity, 2)];
            }
        }

        usort($matches, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * The closest precedent for a question, with the option of this question
     * that says the same as the past answer (null when none does: the past
     * answer is offered as written).
     *
     * @return array{decision: Decision, similarity: float, option: ?string}|null
     */
    public function suggestion(AttentionItem $item): ?array
    {
        if ($item->kind !== AttentionKind::Decision) {
            return null;
        }

        $best = $this->similar($item->question, $item->id, 1)[0] ?? null;

        if ($best === null) {
            return null;
        }

        return [...$best, 'option' => self::matchingOption($best['decision']->answer, $item->orderedOptions())];
    }

    /**
     * @param  list<string>  $options
     */
    public static function matchingOption(string $answer, array $options): ?string
    {
        foreach ($options as $option) {
            if (strcasecmp(trim($option), trim($answer)) === 0) {
                return $option;
            }
        }

        $wanted = self::terms($answer);
        $best = null;
        $score = 0.0;

        foreach ($options as $option) {
            $similarity = self::similarity($wanted, self::terms($option));

            if ($similarity > $score) {
                [$best, $score] = [$option, $similarity];
            }
        }

        return $score >= 0.5 ? $best : null;
    }

    /**
     * The words that carry a text's meaning: lower case, stop words and short
     * words dropped, plurals and -ing/-ed folded, each once.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text))) ?: [];
        $terms = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP, true)) {
                continue;
            }

            $word = (string) preg_replace(['/(?<=..)ies$/', '/(?<=...)(ing|ed)$/', '/(?<=...)s$/'], ['y', '', ''], $word);
            $terms[$word] = true;
        }

        $terms = array_keys($terms);
        sort($terms);

        return $terms;
    }

    /**
     * Jaccard similarity of two term sets.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $a = array_filter($a);
        $b = array_filter($b);

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $union = count(array_unique([...$a, ...$b]));

        return count(array_intersect(array_unique($a), array_unique($b))) / $union;
    }
}
