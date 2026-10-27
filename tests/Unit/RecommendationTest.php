<?php

use App\Models\AttentionItem;

it('resolves a recommendation given by label or by the start of an option to that option', function (?string $recommendation, array $options, ?string $expected) {
    expect(AttentionItem::resolveRecommendation($recommendation, $options))->toBe($expected);
})->with([
    'exact' => ['Per line', ['Per file', 'Per line'], 'Per line'],
    'letter label' => ['A', ['A. Pin the test env', 'B. Fix the runner'], 'A. Pin the test env'],
    'option and letter' => ['Option B', ['A. Pin the test env', 'B) Fix the runner'], 'B) Fix the runner'],
    'bare letter, unlabelled' => ['B', ['Per file', 'Per line'], 'Per line'],
    'number' => ['2', ['Per file', 'Per line'], 'Per line'],
    'label with text' => ['B. Fix the runner: stop exporting', ['A. Pin the test env', 'B. Fix the runner: stop exporting those variables'], 'B. Fix the runner: stop exporting those variables'],
    'case' => ['per LINE', ['Per file', 'Per line'], 'Per line'],
    'start of the text' => ['Fall back to code tools', ['Fall back to code tools with a warning', 'Fail the request'], 'Fall back to code tools with a warning'],
    'an answer of its own' => ['Something else entirely', ['Per file', 'Per line'], 'Something else entirely'],
    'a word starting with a letter is no label' => ['Add a cache', ['A cache', 'B cache'], 'Add a cache'],
    'none' => [null, ['Per file'], null],
]);
